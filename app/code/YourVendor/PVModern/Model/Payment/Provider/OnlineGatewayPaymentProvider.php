<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment\Provider;

use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\Checkout\OrderPaymentStatus;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\VietQrBuilder;

class OnlineGatewayPaymentProvider extends AbstractPaymentProvider
{
    private const BRAND_LABELS = [
        'momo'   => 'MoMo',
        'vnpay'  => 'VNPay',
        'card'   => 'Visa / Mastercard',
        'stripe' => 'Visa / Mastercard',
    ];

    private const BRAND_PROVIDERS = [
        'momo'   => 'momo',
        'vnpay'  => 'vnpay',
        'card'   => 'stripe',
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

        if ($isMock || !$hasCredentials) {
            return $this->initializeVietQrFallback('vnpay', $increment, $amount);
        }

        $params = [
            'vnp_Version' => '2.1.0',
            'vnp_Command' => 'pay',
            'vnp_TmnCode' => (string) $config['tmn_code'],
            'vnp_Amount' => (string) ($amount * 100),
            'vnp_CurrCode' => 'VND',
            'vnp_TxnRef' => preg_replace('/[^A-Za-z0-9_-]/', '', $increment) ?: (string) time(),
            'vnp_OrderInfo' => 'Thanh toan don hang ' . $increment,
            'vnp_OrderType' => 'other',
            'vnp_Locale' => (string) ($config['locale'] ?: 'vn'),
            'vnp_ReturnUrl' => (string) $config['return_url'],
            'vnp_IpAddr' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'vnp_CreateDate' => date('YmdHis'),
            'vnp_ExpireDate' => date('YmdHis', time() + 15 * 60),
        ];

        ksort($params);
        $hashData = [];
        $query = [];
        foreach ($params as $key => $value) {
            $hashData[] = urlencode((string) $key) . '=' . urlencode((string) $value);
            $query[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        $secureHash = hash_hmac('sha512', implode('&', $hashData), (string) $config['hash_secret']);
        $redirectUrl = rtrim((string) $config['payment_url'], '?') . '?' . implode('&', $query) . '&vnp_SecureHash=' . $secureHash;

        return [
            'status' => $this->getInitialStatus(),
            'label' => 'VNPay',
            'provider' => 'vnpay',
            'redirect_url' => $redirectUrl,
            'qr_payload' => $redirectUrl,
            'reference' => $reference,
            'provider_order_id' => (string) $params['vnp_TxnRef'],
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * 60),
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
