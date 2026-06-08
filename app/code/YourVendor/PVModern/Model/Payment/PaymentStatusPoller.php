<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;

class PaymentStatusPoller
{
    public function __construct(
        private readonly IntegrationConfig $integrationConfig,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    
    public function pollAttempt(array $attempt): array
    {
        return match ((string) ($attempt['provider'] ?? '')) {
            'momo' => $this->pollMomo($attempt),
            'stripe' => $this->pollStripe($attempt),
            default => ['checked' => false, 'message' => 'Provider polling is not configured for this method.'],
        };
    }

    
    private function pollMomo(array $attempt): array
    {
        $config = $this->integrationConfig->getMomoConfig();
        $queryUrl = (string) ($config['query_url'] ?? '');
        $secret = (string) ($config['secret_key'] ?? '');
        $partnerCode = (string) ($config['partner_code'] ?? '');
        $accessKey = (string) ($config['access_key'] ?? '');
        $orderId = (string) ($attempt['provider_order_id'] ?? '');
        $requestId = (string) ($attempt['provider_session_id'] ?? $orderId);
        if ($queryUrl === '' || $secret === '' || $partnerCode === '' || $accessKey === '' || $orderId === '') {
            return ['checked' => false, 'message' => 'MoMo query credentials are incomplete.'];
        }

        $rawSignature = 'accessKey=' . $accessKey
            . '&orderId=' . $orderId
            . '&partnerCode=' . $partnerCode
            . '&requestId=' . $requestId;
        $payload = [
            'partnerCode' => $partnerCode,
            'requestId' => $requestId,
            'orderId' => $orderId,
            'lang' => 'vi',
            'signature' => hash_hmac('sha256', $rawSignature, $secret),
        ];
        $response = $this->postJson($queryUrl, $payload);
        if (!$response) {
            return ['checked' => false, 'message' => 'MoMo query returned no JSON response.'];
        }

        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'momo',
            'fallback_query',
            (string) ($response['requestId'] ?? $requestId) ?: null,
            (string) ($response['transId'] ?? '') ?: null,
            true,
            isset($response['amount']) ? (float) $response['amount'] : null,
            'VND',
            $this->json->serialize($response)
        );
        if ((string) ($response['resultCode'] ?? '') === '0') {
            $this->paymentAttemptService->applyProviderResult(
                $attempt,
                $eventId,
                'momo',
                'success',
                (string) ($response['requestId'] ?? $requestId) ?: null,
                (string) ($response['transId'] ?? '') ?: null,
                (float) ($response['amount'] ?? $attempt['amount']),
                'VND',
                true
            );
        } else {
            $this->paymentAttemptService->finishEvent($eventId, 'accepted', 'MoMo query did not return paid status.');
        }

        return ['checked' => true, 'provider' => 'momo', 'resultCode' => (string) ($response['resultCode'] ?? '')];
    }

    
    private function pollStripe(array $attempt): array
    {
        $config = $this->integrationConfig->getStripeConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        $sessionId = (string) ($attempt['provider_session_id'] ?? '');
        if ($secret === '' || $sessionId === '') {
            return ['checked' => false, 'message' => 'Stripe session or secret key is missing.'];
        }

        $response = $this->getJson(rtrim((string) $config['api_base_url'], '/') . '/checkout/sessions/' . rawurlencode($sessionId), $secret);
        if (!$response) {
            return ['checked' => false, 'message' => 'Stripe query returned no JSON response.'];
        }

        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'stripe',
            'fallback_query',
            (string) ($response['id'] ?? $sessionId) ?: null,
            (string) ($response['payment_intent'] ?? '') ?: null,
            true,
            isset($response['amount_total']) ? (float) $response['amount_total'] : null,
            strtoupper((string) ($response['currency'] ?? 'VND')),
            $this->json->serialize($response)
        );
        if ((string) ($response['payment_status'] ?? '') === 'paid') {
            $this->paymentAttemptService->applyProviderResult(
                $attempt,
                $eventId,
                'stripe',
                'success',
                (string) ($response['id'] ?? $sessionId) ?: null,
                (string) ($response['payment_intent'] ?? '') ?: null,
                (float) ($response['amount_total'] ?? $attempt['amount']),
                strtoupper((string) ($response['currency'] ?? 'VND')),
                true
            );
        } else {
            $this->paymentAttemptService->finishEvent($eventId, 'accepted', 'Stripe query did not return paid status.');
        }

        return ['checked' => true, 'provider' => 'stripe', 'payment_status' => (string) ($response['payment_status'] ?? '')];
    }

    
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
            CURLOPT_POSTFIELDS => $this->json->serialize($payload),
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $this->logger->warning('[PVModern][PaymentPoller] POST failed', ['url' => $url, 'error' => curl_error($ch)]);
        }
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    
    private function getJson(string $url, string $bearerToken): array
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
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearerToken],
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $this->logger->warning('[PVModern][PaymentPoller] GET failed', ['url' => $url, 'error' => curl_error($ch)]);
        }
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
