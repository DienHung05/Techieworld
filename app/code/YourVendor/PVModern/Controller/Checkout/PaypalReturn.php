<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class PaypalReturn implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly IntegrationConfig $integrationConfig,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $params = $this->request->getParams();
        $incrementId = $this->extractIncrementId($params);
        $trustedSandboxReturn = $this->isSandboxReturn();
        $processResult = '';

        if ($incrementId !== '' && $trustedSandboxReturn) {
            $processResult = $this->applySandboxReturn($params, $incrementId);
        }

        $isConfirmed = in_array($processResult, ['accepted', 'duplicate'], true);
        $this->logger->info('[PVModern][PayPal] return received', [
            'order_id' => $incrementId,
            'trusted_sandbox_return' => $trustedSandboxReturn,
            'process_result' => $processResult,
            'note' => 'PayPal sandbox browser return updates payment immediately because IPN can be delayed or unavailable in sandbox tests.',
        ]);

        return $this->redirectFactory->create()->setPath('payment-confirmation', [
            '_query' => [
                'orderId' => $incrementId,
                'payment_result' => $isConfirmed ? 'success' : 'pending',
                'gateway' => 'paypal',
            ],
        ]);
    }

    
    private function extractIncrementId(array $params): string
    {
        foreach (['orderId', 'invoice', 'custom', 'cm', 'item_number'] as $key) {
            $value = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($params[$key] ?? '')) ?: '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function isSandboxReturn(): bool
    {
        $paymentUrl = (string) ($this->integrationConfig->getPaypalConfig()['payment_url'] ?? '');
        return stripos($paymentUrl, 'sandbox.paypal.com') !== false;
    }

    
    private function applySandboxReturn(array $payload, string $incrementId): string
    {
        $transactionId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['tx'] ?? $payload['txn_id'] ?? '')) ?: '';
        $attempt = $this->paymentAttemptService->findAttemptForProvider(
            'paypal',
            $incrementId,
            'PAYPAL-' . $incrementId,
            $transactionId,
            $incrementId
        );
        $amount = $attempt ? (float) ($attempt['amount'] ?? 0) : 0.0;
        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'paypal',
            'return',
            $transactionId !== '' ? $transactionId : $incrementId,
            $transactionId !== '' ? $transactionId : null,
            true,
            $amount > 0 ? $amount : null,
            'VND',
            http_build_query($payload)
        );

        if (!$attempt) {
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Payment attempt not found on PayPal return.');
            return 'not_found';
        }

        $status = strtolower((string) ($payload['payment_status'] ?? $payload['st'] ?? 'completed'));
        $providerStatus = in_array($status, ['completed', 'processed', 'success'], true) ? 'success' : 'pending';
        $processResult = $this->paymentAttemptService->applyProviderResult(
            $attempt,
            $eventId,
            'paypal',
            $providerStatus,
            $transactionId !== '' ? $transactionId : 'paypal-return-' . $incrementId,
            $transactionId !== '' ? $transactionId : null,
            $amount,
            'VND',
            true
        );

        return (string) ($processResult['result'] ?? '');
    }
}
