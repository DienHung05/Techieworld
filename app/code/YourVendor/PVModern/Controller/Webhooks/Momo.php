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

class Momo implements HttpPostActionInterface, CsrfAwareActionInterface
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
        $payload = $this->decodePayload($raw);
        $signatureVerified = $this->verifySignature($payload);
        $providerOrderId = (string) ($payload['orderId'] ?? '');
        $requestId = (string) ($payload['requestId'] ?? '');
        $transactionId = (string) ($payload['transId'] ?? '');
        $amount = (float) ($payload['amount'] ?? 0);
        $attempt = $this->paymentAttemptService->findAttemptForProvider(
            'momo',
            $providerOrderId,
            $requestId,
            $transactionId,
            $this->extractIncrementId($providerOrderId)
        );
        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'momo',
            'ipn',
            $requestId !== '' ? $requestId : null,
            $transactionId !== '' ? $transactionId : null,
            $signatureVerified,
            $amount > 0 ? $amount : null,
            'VND',
            $raw !== '' ? $raw : $this->json->serialize($payload)
        );

        if (!$signatureVerified) {
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Invalid MoMo signature.');
            return $result->setHttpResponseCode(401)->setData(['resultCode' => 1, 'message' => 'Invalid signature']);
        }

        if (!$attempt) {
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Payment attempt not found.');
            return $result->setData(['resultCode' => 1, 'message' => 'Order not found']);
        }

        $providerStatus = ((string) ($payload['resultCode'] ?? '')) === '0' ? 'success' : 'failed';
        $processResult = $this->paymentAttemptService->applyProviderResult(
            $attempt,
            $eventId,
            'momo',
            $providerStatus,
            $requestId !== '' ? $requestId : null,
            $transactionId !== '' ? $transactionId : null,
            $amount,
            'VND',
            true
        );

        $this->logger->info('[PVModern][MoMo] verified IPN processed', [
            'order_id' => $providerOrderId,
            'transaction_id' => $transactionId,
            'result' => $processResult['result'] ?? '',
        ]);

        return $result->setData(['resultCode' => 0, 'message' => 'Received']);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $raw): array
    {
        if ($raw !== '') {
            try {
                $decoded = $this->json->unserialize($raw);
                return is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                return [];
            }
        }

        return $this->request->getParams();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifySignature(array $payload): bool
    {
        $config = $this->integrationConfig->getMomoConfig();
        $secret = (string) ($config['secret_key'] ?? '');
        $accessKey = (string) ($payload['accessKey'] ?? $config['access_key'] ?? '');
        $signature = (string) ($payload['signature'] ?? '');
        if ($secret === '' || $signature === '') {
            return false;
        }

        $raw = 'accessKey=' . $accessKey .
            '&amount=' . (string) ($payload['amount'] ?? '') .
            '&extraData=' . (string) ($payload['extraData'] ?? '') .
            '&message=' . (string) ($payload['message'] ?? '') .
            '&orderId=' . (string) ($payload['orderId'] ?? '') .
            '&orderInfo=' . (string) ($payload['orderInfo'] ?? '') .
            '&orderType=' . (string) ($payload['orderType'] ?? '') .
            '&partnerCode=' . (string) ($payload['partnerCode'] ?? '') .
            '&payType=' . (string) ($payload['payType'] ?? '') .
            '&requestId=' . (string) ($payload['requestId'] ?? '') .
            '&responseTime=' . (string) ($payload['responseTime'] ?? '') .
            '&resultCode=' . (string) ($payload['resultCode'] ?? '') .
            '&transId=' . (string) ($payload['transId'] ?? '');

        return hash_equals(hash_hmac('sha256', $raw, $secret), $signature);
    }

    private function extractIncrementId(string $gatewayOrderId): string
    {
        if (str_starts_with($gatewayOrderId, 'MOMO-')) {
            return substr($gatewayOrderId, 5);
        }

        return preg_replace('/[^A-Za-z0-9_-]/', '', $gatewayOrderId) ?: '';
    }
}
