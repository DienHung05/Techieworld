<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Qr;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;


class Url implements HttpGetActionInterface
{
    private const UPSTREAM_TEMPLATE = 'https://api.qrserver.com/v1/create-qr-code/?size=%dx%d&margin=10&format=png&qzone=2&data=%s';
    private const TIMEOUT_SEC = 4;
    private const MAX_DATA_LEN = 600;
    
    private const CACHE_TTL_SEC = 1800;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory
    ) {
    }

    public function execute()
    {
        $data = (string) $this->request->getParam('data', '');
        if ($data === '' || strlen($data) > self::MAX_DATA_LEN) {
            return $this->errorPng(400);
        }
        $size = max(120, min(1024, (int) $this->request->getParam('size', 540)));

        $cacheKey = sha1($size . '|' . $data);
        $cachePath = $this->cachePath($cacheKey);
        $body = $this->readCache($cachePath);

        if ($body === null) {
            $url = sprintf(
                self::UPSTREAM_TEMPLATE,
                $size,
                $size,
                rawurlencode($data)
            );
            [$body, , $status] = $this->fetchUpstream($url);
            if ($status !== 200 || $body === '' || $body === null) {
                return $this->errorPng(502);
            }
            $this->writeCache($cachePath, $body);
        }

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'image/png', true);
        
        $result->setHeader('Cache-Control', 'public, max-age=31536000, immutable', true);
        $result->setHeader('X-QR-Source', 'qrserver-cached', true);
        $result->setContents($body);
        return $result;
    }

    private function cachePath(string $key): string
    {
        $dir = BP . '/var/cache/pvmodern_qr';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . $key . '.png';
    }

    private function readCache(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $mtime = @filemtime($path);
        if ($mtime === false || time() - $mtime > self::CACHE_TTL_SEC) {
            return null;
        }
        $body = @file_get_contents($path);
        return is_string($body) && $body !== '' ? $body : null;
    }

    private function writeCache(string $path, string $body): void
    {
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $body, LOCK_EX) === strlen($body)) {
            @rename($tmp, $path);
        }
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
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_USERAGENT      => 'PVModern-Magento-QR-Url-Proxy/1.0',
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
        $result->setContents($pixel);
        return $result;
    }
}
