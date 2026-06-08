<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use YourVendor\PVModern\Model\IntegrationConfig;

class VietQrBuilder
{
    public function __construct(
        private readonly IntegrationConfig $integrationConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    
    public function buildUrl(int $amount, string $addInfo): string
    {
        $details = $this->integrationConfig->getBankTransferDetails();
        $account = preg_replace('/\s+/', '', (string) ($details['account_number'] ?? ''));
        if ($account === '') {
            return '';
        }

        
        
        
        
        if ($this->integrationConfig->getBool('PVMODERN_PAYMENT_DEMO', false)) {
            $scanUrl = $this->buildPublicUrl('/api/qr/scanpaid?order=' . urlencode($addInfo));
            return '/api/qr/url?size=540&data=' . urlencode($scanUrl);
        }

        $provider = strtolower((string) $this->integrationConfig->getString('PVMODERN_QR_PROVIDER', 'sepay'));

        if ($provider === 'sepay') {
            
            
            
            
            
            
            
            
            $bankCode = strtoupper((string) ($details['bank_code'] ?? 'BIDV'));
            $params = http_build_query(array_filter([
                'acc' => $account,
                'bank' => $bankCode,
                'amount' => $amount > 0 ? $amount : null,
                'des' => $addInfo,
                'template' => 'compact',
            ]));
            return sprintf('/api/qr/sepay?%s', $params);
        }

        
        $bin = preg_replace('/\s+/', '', (string) ($details['bank_bin'] ?? ''));
        if ($bin === '') {
            return '';
        }
        $params = http_build_query(array_filter([
            'amount' => $amount > 0 ? $amount : null,
            'addInfo' => $addInfo,
            'accountName' => $details['account_name'] ?? '',
        ]));
        return sprintf('https://img.vietqr.io/image/%s-%s-compact2.png?%s', $bin, $account, $params);
    }

    
    public function getMerchantDetails(): array
    {
        return $this->integrationConfig->getBankTransferDetails();
    }

    
    private function buildPublicUrl(string $path): string
    {
        $base = trim((string) $this->integrationConfig->getString('PVMODERN_PUBLIC_BASE_URL', ''));

        if ($base === '') {
            try {
                $store = $this->storeManager->getStore();
                $base = (string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $store->isCurrentlySecure());
            } catch (\Throwable $e) {
                $base = '';
            }
        }

        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = $scheme . '://' . $host;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
