<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment\Provider;

use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\Checkout\OrderPaymentStatus;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\VietQrBuilder;

class OnlineGatewayPaymentProvider extends AbstractPaymentProvider
{
    private const VNPAY_CLOCK_CACHE_TTL = 300;

    /** @var array{checked_at:int, offset:int}|null */
    private static ?array $vnpayClockOffset = null;

    private const BRAND_LABELS = [
        'momo'   => 'MoMo',
        'vnpay'  => 'VNPay',
        'card'   => 'PayPal',
        'paypal' => 'PayPal',
        'stripe' => 'Visa / Mastercard',
    ];

    private const BRAND_PROVIDERS = [
        'momo'   => 'momo',
        'vnpay'  => 'vnpay',
        'card'   => 'paypal',
        'paypal' => 'paypal',
        'stripe' => 'stripe',
    ];

    public function __construct(
        IntegrationConfig $integrationConfig,
        LoggerInterface $logger,
        private readonly VietQrBuilder $vietQrBuilder
    ) {
        parent::__construct($integrationConfig, $logger);
    }

    public function getCode(): string
    {
        return 'online_gateway';
    }

    public function getLabel(): string
    {
        return 'Online Payment Gateway';
    }

    public function getMethodCode(): string
    {
        return 'pvmodern_onlinegateway';
    }

    public function getInitialStatus(): string
    {
        return OrderPaymentStatus::AWAITING_PAYMENT;
    }

    public function describeCheckoutMethod(array $context = []): array
    {
        return parent::describeCheckoutMethod($context) + [
            'description' => 'Gateway abstraction for future VNPay, MoMo, ZaloPay, or card processing.',
        ];
    }

    public function initialize(array $context): array
    {
        $config = $this->integrationConfig->getGatewayConfig();
        $isMock = $this->integrationConfig->isMockModeEnabled('payment');
        $increment = $context['order_increment_id'] ?? 'PENDING';
        $channel = strtolower((string) ($context['gateway_channel'] ?? $context['wallet_id'] ?? 'vnpay'));
        $amount = max(1000, (int) round((float) ($context['amount'] ?? 0)));

        if ($channel === 'momo') {
            return $this->initializeMomo($increment, $amount, $isMock);
        }

        if ($channel === 'vnpay') {
            return $this->initializeVnpay($increment, $amount, $isMock);
        }

        if ($channel === 'paypal') {
            return $this->initializePaypal($increment, $amount, $isMock);
        }

        if ($channel === 'stripe' || $channel === 'card') {
            return $this->initializeStripe($increment, $amount, $isMock);
        }

        return [
            'status' => $this->getInitialStatus(),
            'label' => $this->getLabel(),
            'redirect_url' => $isMock ? '' : '/todo-live-gateway-redirect',
            'reference' => sprintf('GW-%s-%s', $config['merchant_code'], $increment),
            'message' => $isMock
                ? 'Mock gateway initialized. Replace with a live redirect/init endpoint when credentials are ready.'
                : 'Gateway initialized.',
            'mock' => $isMock,
        ];
    }

    private function initializeVnpay(string $increment, int $amount, bool $isMock): array
    {
        $config = $this->integrationConfig->getVnpayConfig();
        $hasCredentials = !empty($config['tmn_code']) && !empty($config['hash_secret']);
        $reference = 'VNPAY-' . $increment;

        if (!$hasCredentials) {
            return $this->initializeVietQrFallback('vnpay', $increment, $amount);
        }

        $expireMinutes = max(5, min(60, (int) ($config['expire_minutes'] ?? 15)));
        $paymentUrl = rtrim((string) $config['payment_url'], '?');
        $createdAtTs = $this->resolveVnpayTimestamp($paymentUrl);
        $expiresAtTs = $createdAtTs + ($expireMinutes * 60);
        $timezone = new \DateTimeZone('Asia/Ho_Chi_Minh');
        $createdAt = (new \DateTimeImmutable('@' . $createdAtTs))->setTimezone($timezone);
        $expiresAt = (new \DateTimeImmutable('@' . $expiresAtTs))->setTimezone($timezone);
        $txnRef = preg_replace('/[^A-Za-z0-9_-]/', '', $increment) ?: (string) $createdAtTs;

        $params = [
            'vnp_Version' => '2.1.0',
            'vnp_Command' => 'pay',
            'vnp_TmnCode' => (string) $config['tmn_code'],
            'vnp_Amount' => (string) ($amount * 100),
            'vnp_CurrCode' => 'VND',
            'vnp_TxnRef' => $txnRef,
            'vnp_OrderInfo' => 'Thanh toan don hang ' . $increment,
            'vnp_OrderType' => 'other',
            'vnp_Locale' => (string) ($config['locale'] ?: 'vn'),
            'vnp_ReturnUrl' => (string) $config['return_url'],
            'vnp_IpAddr' => $this->resolveClientIp(),
            'vnp_CreateDate' => $createdAt->format('YmdHis'),
            'vnp_ExpireDate' => $expiresAt->format('YmdHis'),
        ];

        ksort($params);
        $hashData = [];
        $query = [];
        foreach ($params as $key => $value) {
            $hashData[] = urlencode((string) $key) . '=' . urlencode((string) $value);
            $query[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        $secureHash = hash_hmac('sha512', implode('&', $hashData), (string) $config['hash_secret']);
        $redirectUrl = $paymentUrl . '?' . implode('&', $query) . '&vnp_SecureHash=' . $secureHash;
        $qrCodeUrl = '/api/qr/url?size=540&data=' . rawurlencode($redirectUrl);

        return [
            'status' => $this->getInitialStatus(),
            'label' => 'VNPay',
            'provider' => 'vnpay',
            'redirect_url' => $redirectUrl,
            'payment_url' => $redirectUrl,
            'qr_code_url' => $qrCodeUrl,
            'qr_payload' => $redirectUrl,
            'reference' => $reference,
            'provider_order_id' => (string) $params['vnp_TxnRef'],
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAtTs),
            'expires_at_epoch' => $expiresAtTs,
            'expiresAt' => gmdate('c', $expiresAtTs),
            'expiresAtEpoch' => $expiresAtTs,
            'amount' => $amount,
            'message' => 'VNPay payment URL created.',
            'mock' => false,
        ];
    }

    private function initializeMomo(string $increment, int $amount, bool $isMock): array
    {
        $config = $this->integrationConfig->getMomoConfig();
        $hasCredentials = !empty($config['partner_code']) && !empty($config['access_key']) && !empty($config['secret_key']);
        $reference = 'MOMO-' . $increment;

        if ($isMock || !$hasCredentials) {
            return $this->initializeVietQrFallback('momo', $increment, $amount);
        }

        $requestId = $reference . '-' . time();
        $orderId = preg_replace('/[^A-Za-z0-9_.-]/', '', $reference) ?: $requestId;
        $extraData = base64_encode(json_encode(['order' => $increment], JSON_UNESCAPED_SLASHES));
        $rawSignature = sprintf(
            'accessKey=%s&amount=%s&extraData=%s&ipnUrl=%s&orderId=%s&orderInfo=%s&partnerCode=%s&redirectUrl=%s&requestId=%s&requestType=%s',
            $config['access_key'],
            $amount,
            $extraData,
            $config['ipn_url'],
            $orderId,
            'Thanh toan don hang ' . $increment,
            $config['partner_code'],
            $config['redirect_url'],
            $requestId,
            $config['request_type']
        );

        $payload = [
            'partnerCode' => $config['partner_code'],
            'accessKey' => $config['access_key'],
            'requestId' => $requestId,
            'amount' => $amount,
            'orderId' => $orderId,
            'orderInfo' => 'Thanh toan don hang ' . $increment,
            'redirectUrl' => $config['redirect_url'],
            'ipnUrl' => $config['ipn_url'],
            'extraData' => $extraData,
            'requestType' => $config['request_type'],
            'lang' => 'vi',
            'signature' => hash_hmac('sha256', $rawSignature, (string) $config['secret_key']),
        ];

        $response = $this->postJson((string) $config['endpoint'], $payload);
        $payUrl = (string) ($response['payUrl'] ?? $response['shortLink'] ?? '');
        $qrCodeUrl = (string) ($response['qrCodeUrl'] ?? '');

        return [
            'status' => $this->getInitialStatus(),
            'label' => 'MoMo',
            'provider' => 'momo',
            'redirect_url' => $payUrl,
            'qr_payload' => $payUrl,
            'qr_code_url' => $qrCodeUrl,
            'deeplink_url' => (string) ($response['deeplink'] ?? $response['deeplinkUrl'] ?? ''),
            'reference' => $reference,
            'provider_order_id' => $orderId,
            'provider_session_id' => $requestId,
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
            'amount' => $amount,
            'message' => $payUrl !== '' ? 'MoMo payUrl created.' : 'MoMo did not return a payUrl.',
            'mock' => false,
            'gateway_response' => $response,
        ];
    }

    private function resolveVnpayTimestamp(string $paymentUrl): int
    {
        $now = time();
        if (self::$vnpayClockOffset !== null
            && ($now - (int) self::$vnpayClockOffset['checked_at']) < self::VNPAY_CLOCK_CACHE_TTL
        ) {
            return $now + (int) self::$vnpayClockOffset['offset'];
        }

        $remoteTimestamp = $this->fetchRemoteDateTimestamp($paymentUrl);
        $offset = $remoteTimestamp > 0 ? ($remoteTimestamp - $now) : 0;
        self::$vnpayClockOffset = [
            'checked_at' => $now,
            'offset' => abs($offset) >= 30 ? $offset : 0,
        ];

        return $now + (int) self::$vnpayClockOffset['offset'];
    }

    private function resolveClientIp(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            foreach (explode(',', $candidate) as $ip) {
                $ip = trim($ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    private function fetchRemoteDateTimestamp(string $paymentUrl): int
    {
        if (!function_exists('curl_init')) {
            return 0;
        }

        $parts = parse_url($paymentUrl);
        $baseUrl = (($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'sandbox.vnpayment.vn') . '/');
        $ch = curl_init($baseUrl);
        if (!$ch) {
            return 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Techieworld VNPay clock sync',
        ]);
        $headers = curl_exec($ch);
        curl_close($ch);
        if (!is_string($headers) || $headers === '') {
            return 0;
        }

        if (!preg_match('/^Date:\s*(.+)$/mi', $headers, $matches)) {
            return 0;
        }

        $timestamp = strtotime(trim($matches[1]));
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    private function initializeStripe(string $increment, int $amount, bool $isMock): array
    {
        $config = $this->integrationConfig->getStripeConfig();
        $secretKey = (string) ($config['secret_key'] ?? '');
        $reference = 'STRIPE-' . preg_replace('/[^A-Za-z0-9_-]/', '', $increment);

        if ($isMock || $secretKey === '') {
            return $this->initializeVietQrFallback('card', $increment, $amount);
        }

        $payload = [
            'mode' => 'payment',
            'success_url' => (string) $config['success_url'],
            'cancel_url' => (string) $config['cancel_url'],
            'payment_method_types' => ['card'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'vnd',
                    'unit_amount' => $amount,
                    'product_data' => [
                        'name' => 'Techieworld order ' . $increment,
                    ],
                ],
            ]],
            'metadata' => [
                'order_increment_id' => $increment,
                'provider_order_id' => $reference,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'order_increment_id' => $increment,
                    'provider_order_id' => $reference,
                ],
            ],
        ];

        $response = $this->postForm((string) $config['checkout_sessions_url'], $payload, $secretKey);
        $paymentUrl = (string) ($response['url'] ?? '');

        return [
            'status' => $this->getInitialStatus(),
            'label' => 'Visa / Mastercard',
            'provider' => 'stripe',
            'redirect_url' => $paymentUrl,
            'qr_payload' => $paymentUrl,
            'reference' => $reference,
            'provider_order_id' => $reference,
            'provider_session_id' => (string) ($response['id'] ?? ''),
            'provider_transaction_id' => (string) ($response['payment_intent'] ?? ''),
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
            'amount' => $amount,
            'message' => $paymentUrl !== '' ? 'Stripe Checkout session created.' : 'Stripe did not return a checkout URL.',
            'mock' => false,
            'gateway_response' => $response,
        ];
    }

    private function initializePaypal(string $increment, int $amount, bool $isMock): array
    {
        $config = $this->integrationConfig->getPaypalConfig();
        $businessAccount = (string) ($config['business_account'] ?? '');

        if ($isMock || $businessAccount === '') {
            return $this->initializeVietQrFallback('paypal', $increment, $amount);
        }

        $reference = 'PAYPAL-' . preg_replace('/[^A-Za-z0-9_-]/', '', $increment);
        $currency = strtoupper((string) ($config['currency'] ?? 'USD'));
        $displayAmount = (float) $amount;
        if ($currency !== 'VND') {
            $rate = max(1.0, (float) ($config['vnd_to_usd_rate'] ?? 25000));
            $displayAmount = round($amount / $rate, 2);
        }

        $returnUrl = $this->appendQuery((string) $config['return_url'], [
            'orderId' => $increment,
            'gateway' => 'paypal',
        ]);
        $cancelUrl = $this->appendQuery((string) $config['cancel_url'], [
            'orderId' => $increment,
            'payment_result' => 'failed',
            'gateway' => 'paypal',
        ]);

        $params = [
            'cmd' => '_xclick',
            'business' => $businessAccount,
            'item_name' => 'Techieworld order ' . $increment,
            'item_number' => $increment,
            'invoice' => $increment,
            'custom' => $increment,
            'amount' => number_format($displayAmount, 2, '.', ''),
            'currency_code' => $currency,
            'return' => $returnUrl,
            'cancel_return' => $cancelUrl,
            'notify_url' => (string) $config['notify_url'],
            'no_shipping' => '1',
            'rm' => '1',
            'charset' => 'utf-8',
        ];
        $paymentUrl = rtrim((string) $config['payment_url'], '?') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return [
            'status' => $this->getInitialStatus(),
            'label' => 'PayPal',
            'provider' => 'paypal',
            'redirect_url' => $paymentUrl,
            'payment_url' => $paymentUrl,
            'qr_payload' => $paymentUrl,
            'reference' => $reference,
            'provider_order_id' => $increment,
            'provider_session_id' => $reference,
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
            'amount' => $amount,
            'message' => 'PayPal sandbox payment URL created.',
            'mock' => false,
            'gateway_response' => [
                'paypal_amount' => $displayAmount,
                'paypal_currency' => $currency,
                'paypal_business_account' => $businessAccount,
                'paypal_personal_account' => (string) ($config['personal_account'] ?? ''),
            ],
            'paypal_accounts' => [
                'business' => $businessAccount,
                'personal' => (string) ($config['personal_account'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    private function appendQuery(string $url, array $query): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Sandbox/no-credentials fallback: every "online gateway" method (momo/vnpay/card)
     * returns a dynamic BIDV VietQR. Customer scans → bank app auto-fills amount + memo →
     * Casso webhook detects the ORD<digits> reference → SSE pushes paid status to the browser.
     *
     * @return array<string, mixed>
     */
    private function initializeVietQrFallback(string $channel, string $increment, int $amount): array
    {
        $details = $this->vietQrBuilder->getMerchantDetails();
        $transferCode = 'ORD' . preg_replace('/[^0-9]/', '', $increment);
        if ($transferCode === 'ORD') {
            $transferCode = sprintf('ORD%d', time());
        }

        $qrCodeUrl = $this->vietQrBuilder->buildUrl($amount, $transferCode);
        $label = self::BRAND_LABELS[$channel] ?? 'Bank Transfer';
        $provider = self::BRAND_PROVIDERS[$channel] ?? 'bank_transfer';

        return [
            'status' => $this->getInitialStatus(),
            'label' => $label,
            'provider' => $provider,
            'redirect_url' => '',
            'qr_code_url' => $qrCodeUrl,
            'qr_payload' => $transferCode,
            'qr_channel_brand' => $channel,
            'reference' => $transferCode,
            'provider_order_id' => $transferCode,
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
            'amount' => $amount,
            'instructions' => [
                'account_name' => $details['account_name'] ?? '',
                'account_number' => $details['account_number'] ?? '',
                'bank_name' => $details['bank_name'] ?? '',
                'branch' => $details['branch'] ?? '',
                'transfer_reference' => $transferCode,
            ],
            'message' => sprintf('%s VietQR — amount auto-filled, auto-detected by Casso webhook.', $label),
            'mock' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 12,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postForm(string $url, array $payload, string $secretKey): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secretKey],
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_TIMEOUT => 12,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
