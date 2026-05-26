<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Qr;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * Same-origin proxy for SePay's qr.sepay.vn image generator.
 *
 * Magento's default Content-Security-Policy `img-src` allowlist does NOT
 * include `qr.sepay.vn`, so embedding a direct https://qr.sepay.vn/img link
 * results in a silently blocked image (the QR renders as broken placeholder
 * squares — see customer report 2026-05-15). Routing through this controller
 * means the `<img src>` is `'self'` which CSP always permits.
 *
 * Caching: we cache the upstream PNG for 5 minutes per (acc,amount,des) tuple
 * via a Cache-Control response header. Each QR is unique per order so the
 * cache mostly serves repeat views of the same checkout page (refresh,
 * back-button, mobile bank-app pre-fetch).
 *
 * Route: GET /api/qr/sepay?acc=...&amount=...&des=...&bank=BIDV
 */
class Sepay implements HttpGetActionInterface
{
    private const UPSTREAM = 'https://qr.sepay.vn/img';
    private const TIMEOUT_SEC = 10;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly ResponseInterface $response
    ) {
    }

    public function execute()
    {
        // Whitelist the query params we forward; reject everything else so
        // this can't be used as an open proxy.
        $allowed = ['acc', 'bank', 'amount', 'des', 'template', 'download'];
        $params = [];
        foreach ($allowed as $key) {
            $value = (string) $this->request->getParam($key, '');
            if ($value === '') {
                continue;
            }
            // Sanitize: alphanumeric + safe punctuation only.
            $clean = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $value);
            if ($clean === null || $clean === '') {
                continue;
            }
            $params[$key] = $clean;
        }

        if (empty($params['acc']) || empty($params['des'])) {
            return $this->errorPng(400);
        }

        // Force template=compact if missing (smallest, fits our card)
        if (empty($params['template'])) {
            $params['template'] = 'compact';
        }
        if (empty($params['bank'])) {
            $params['bank'] = 'BIDV';
        }

        $upstreamUrl = self::UPSTREAM . '?' . http_build_query($params);

        [$body, $contentType, $status] = $this->fetchUpstream($upstreamUrl);
        if ($status !== 200 || $body === '') {
            return $this->errorPng(502);
        }

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', $contentType ?: 'image/png', true);
        $result->setHeader('Cache-Control', 'public, max-age=300, immutable', true);
        $result->setHeader('X-QR-Source', 'sepay', true);
        $result->setContents($body);
        return $result;
    }

    /**
     * @return array{0:string,1:string,2:int}
     */
    private function fetchUpstream(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['', '', 0];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['', '', 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER => ['Accept: image/png,image/*;q=0.9,*/*;q=0.8'],
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => 'PVModern-Magento-QR-Proxy/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return [is_string($body) ? $body : '', $contentType, $status];
    }

    /**
     * Returns a tiny 1x1 transparent PNG with the requested HTTP status.
     * Better than a 500 error page because the <img> tag stays valid and
     * the JS `onerror` fallback can kick in.
     */
    private function errorPng(int $status)
    {
        // 1x1 transparent PNG
        $pixel = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII='
        );
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Content-Type', 'image/png', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setContents($pixel);
        return $result;
    }
}
