<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Webhooks;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class Stripe implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $json,
        private readonly IntegrationConfig $integrationConfig,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $raw = (string) $this->request->getContent();
        $signature = (string) $this->request->getHeader('Stripe-Signature');
        if (!$this->verifySignature($raw, $signature)) {
            $eventId = $this->paymentAttemptService->recordEvent(
                null,
                'stripe',
                'webhook',
                null,
                null,
                false,
                null,
                'VND',
                $raw,
                'rejected',
                'Invalid Stripe signature.'
            );
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Invalid Stripe signature.');
            return $result->setHttpResponseCode(400)->setData(['received' => false, 'message' => 'Invalid signature']);
        }

        try {
            $event = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return $result->setHttpResponseCode(400)->setData(['received' => false, 'message' => 'Invalid JSON']);
        }

        if (!is_array($event)) {
            return $result->setHttpResponseCode(400)->setData(['received' => false, 'message' => 'Invalid event']);
        }

        $eventType = (string) ($event['type'] ?? '');
        $providerEventId = (string) ($event['id'] ?? '');
        $object = (array) ($event['data']['object'] ?? []);
        $metadata = (array) ($object['metadata'] ?? []);
        $sessionId = $eventType === 'checkout.session.completed' ? (string) ($object['id'] ?? '') : '';
        $paymentIntent = (string) ($object['payment_intent'] ?? $object['id'] ?? '');
        $providerOrderId = (string) ($metadata['provider_order_id'] ?? '');
        $incrementId = (string) ($metadata['order_increment_id'] ?? '');
        $amount = (float) ($object['amount_total'] ?? $object['amount_received'] ?? $object['amount'] ?? 0);
        $currency = strtoupper((string) ($object['currency'] ?? 'VND'));
        $attempt = $this->paymentAttemptService->findAttemptForProvider(
            'stripe',
            $providerOrderId,
            $sessionId,
            $paymentIntent,
            $incrementId
        );
        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'stripe',
            $eventType,
            $providerEventId !== '' ? $providerEventId : null,
            $paymentIntent !== '' ? $paymentIntent : null,
            true,
            $amount > 0 ? $amount : null,
            $currency,
            $raw
        );

        if (!$attempt) {
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Payment attempt not found.');
            return $result->setData(['received' => true]);
        }

        if ($eventType === 'checkout.session.completed') {
            if ((string) ($object['payment_status'] ?? '') !== 'paid') {
                $this->paymentAttemptService->finishEvent($eventId, 'accepted', 'Checkout completed without paid status.');
                return $result->setData(['received' => true]);
            }
            $this->paymentAttemptService->applyProviderResult(
                $attempt,
                $eventId,
                'stripe',
                'success',
                $providerEventId !== '' ? $providerEventId : null,
                $paymentIntent !== '' ? $paymentIntent : $sessionId,
                $amount,
                $currency,
                true
            );
        } elseif ($eventType === 'payment_intent.succeeded') {
            $this->paymentAttemptService->applyProviderResult(
                $attempt,
                $eventId,
                'stripe',
                'success',
                $providerEventId !== '' ? $providerEventId : null,
                $paymentIntent,
                $amount,
                $currency,
                true
            );
        } elseif ($eventType === 'payment_intent.payment_failed') {
            $this->paymentAttemptService->applyProviderResult(
                $attempt,
                $eventId,
                'stripe',
                'payment_failed',
                $providerEventId !== '' ? $providerEventId : null,
                $paymentIntent,
                (float) ($attempt['amount'] ?? $amount),
                $currency,
                true
            );
        } else {
            $this->paymentAttemptService->finishEvent($eventId, 'accepted', 'Unhandled Stripe event type.');
        }

        $this->logger->info('[PVModern][Stripe] webhook processed', [
            'event_id' => $providerEventId,
            'event_type' => $eventType,
            'session_id' => $sessionId,
            'payment_intent' => $paymentIntent,
        ]);

        return $result->setData(['received' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    private function verifySignature(string $payload, string $header): bool
    {
        $secret = (string) ($this->integrationConfig->getStripeConfig()['webhook_secret'] ?? '');
        if ($secret === '' || $payload === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            if ($key !== '') {
                $parts[$key][] = $value;
            }
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);
        if ($timestamp <= 0 || abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }
}
