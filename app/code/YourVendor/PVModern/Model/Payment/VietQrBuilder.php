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

    /**
     * Build a dynamic VietQR image URL that auto-fills amount + memo when scanned
     * by any Vietnamese banking app or MoMo/VNPay wallet (Napas 247 standard).
     *
     * Defaults to SePay's QR generator (qr.sepay.vn) so SePay detects the incoming
     * transfer in real-time the moment the bank confirms it. Money still lands in
     * the same configured BIDV account; SePay just monitors it via the linked
     * BIDV OAuth connection. Set PVMODERN_QR_PROVIDER=vietqr to switch back to
     * the generic img.vietqr.io builder (no realtime detection).
     */
    public function buildUrl(int $amount, string $addInfo): string
    {
        $details = $this->integrationConfig->getBankTransferDetails();
        $account = preg_replace('/\s+/', '', (string) ($details['account_number'] ?? ''));
        if ($account === '') {
            return '';
        }

        // Demo mode: emit a URL-encoded QR pointing at /api/qr/scanpaid?order=...
        // (Magento route → Controller\Qr\ScanPaid). When a phone camera scans
        // this QR, the camera/browser opens the URL → ScanPaid fires a simulated
        // paid event → SSE pushes step 5 to the open browser tab on the desktop.
        if ($this->integrationConfig->getBool('PVMODERN_PAYMENT_DEMO', false)) {
            $scanUrl = $this->buildPublicUrl('/api/qr/scanpaid?order=' . urlencode($addInfo));
            return '/api/qr/url?size=540&data=' . urlencode($scanUrl);
        }

        $provider = strtolower((string) $this->integrationConfig->getString('PVMODERN_QR_PROVIDER', 'sepay'));

        if ($provider === 'sepay') {
            // SePay QR — routes through SePay's transaction monitor for instant
            // webhook delivery once the customer's bank confirms the transfer.
            // Served via a same-origin Magento proxy controller (Controller/Qr/Sepay.php)
            // so Magento's restrictive default CSP `img-src` allowlist doesn't block
            // qr.sepay.vn (which isn't in the default list). The proxy fetches the
            // real PNG server-side and re-serves it under /api/qr/sepay so the
            // browser sees a `'self'` URL.
            // https://docs.sepay.vn/tao-ma-vietqr-mien-phi.html
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

        // Fallback: plain VietQR (no realtime detection)
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

    /**
     * @return array<string, string>
     */
    public function getMerchantDetails(): array
    {
        return $this->integrationConfig->getBankTransferDetails();
    }

    /**
     * Resolve a full public URL for an internal path. Order of preference:
     *   1. PVMODERN_PUBLIC_BASE_URL (explicit override)
     *   2. The current store's configured base URL via Magento's StoreManager
     *      (this is the same URL the storefront is actually served from, so a
     *      phone on the same network resolves it correctly)
     *   3. Request HTTP_HOST as a last-resort fallback
     */
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
