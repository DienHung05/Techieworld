<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\PaymentSessions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use YourVendor\PVModern\Helper\PaymentDb;

class Status implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PaymentDb $paymentDb
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache', true);
        $attempt = $this->loadAttempt();
        if (!$attempt) {
            return $result->setHttpResponseCode(404)->setData(['success' => false, 'message' => 'Payment attempt not found.']);
        }

        return $result->setData($this->formatAttempt($attempt));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadAttempt(): ?array
    {
        $id = (int) ($this->request->getParam('paymentAttemptId') ?: $this->request->getParam('payment_attempt_id') ?: $this->request->getParam('id'));
        if ($id > 0) {
            return $this->paymentDb->findAttemptById($id);
        }

        $orderId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($this->request->getParam('orderId') ?: $this->request->getParam('order_id'))) ?: '';
        return $orderId !== '' ? $this->paymentDb->findLatestAttemptForIncrement($orderId) : null;
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function formatAttempt(array $attempt): array
    {
        return [
            'success' => true,
            'paymentAttemptId' => (int) $attempt['id'],
            'orderId' => (string) $attempt['magento_increment_id'],
            'method' => (string) $attempt['provider'],
            'status' => (string) $attempt['status'],
            'paidAt' => !empty($attempt['paid_at']) ? gmdate('c', strtotime((string) $attempt['paid_at'])) : null,
            'providerTransactionId' => (string) ($attempt['provider_transaction_id'] ?? ''),
            'nextStepAllowed' => (string) $attempt['status'] === 'paid',
        ];
    }
}
