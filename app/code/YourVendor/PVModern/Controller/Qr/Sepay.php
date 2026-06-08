<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Qr;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;


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
        
        
        $allowed = ['acc', 'bank', 'amount', 'des', 'template', 'download'];
        $params = [];
        foreach ($allowed as $key) {
            $value = (string) $this->request->getParam($key, '');
            if ($value === '') {
                continue;
            }
            
            $clean = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $value);
            if ($clean === null || $clean === '') {
                continue;
            }
            $params[$key] = $clean;
        }

        if (empty($params['acc']) || empty($params['des'])) {
            return $this->errorPng(400);
        }

        
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

    
    private function errorPng(int $status)
    {
        
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
