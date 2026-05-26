<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Payments;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use YourVendor\PVModern\Model\Checkout\OrderPaymentStatus;

class Status implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory
    ) {
    }

    public function execute()
    {
        $orderId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->request->getParam('orderId', $this->request->getParam('order_id', ''))) ?: '';
        $paymentId = trim((string) $this->request->getParam('paymentId', $this->request->getParam('payment_id', '')));
        $order = $orderId !== '' ? $this->findOrder($orderId) : null;

        $status = 'pending';
        $gateway = '';
        $updatedAt = gmdate('c');
        if ($order && $order->getPayment()) {
            $storedStatus = (string) $order->getPayment()->getAdditionalInformation('pvmodern_payment_status');
            $status = $this->normalizeStatus($storedStatus ?: (string) $order->getStatus());
            $context = (string) $order->getPayment()->getAdditionalInformation('pvmodern_payment_context');
            if ($context !== '') {
                $decoded = json_decode($context, true);
                if (is_array($decoded)) {
                    $gateway = (string) ($decoded['provider'] ?? $decoded['label'] ?? '');
                }
            }
            $updatedAt = $order->getUpdatedAt() ? gmdate('c', strtotime((string) $order->getUpdatedAt())) : $updatedAt;
        }

        return $this->resultJsonFactory->create()->setData([
            'success' => true,
            'paymentId' => $paymentId,
            'orderId' => $order ? (string) $order->getIncrementId() : $orderId,
            'status' => $status,
            'gateway' => $gateway,
            'updatedAt' => $updatedAt,
            'message' => $order ? 'Payment status loaded from order payment information.' : 'Order not found; payment remains pending.',
        ]);
    }

    private function findOrder(string $incrementId): ?\Magento\Sales\Model\Order
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId);
        $collection->setPageSize(1);
        $order = $collection->getFirstItem();
        return $order && $order->getId() ? $order : null;
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            OrderPaymentStatus::PAID, 'paid', 'complete', 'processing' => 'paid',
            OrderPaymentStatus::FAILED, 'failed' => 'failed',
            OrderPaymentStatus::EXPIRED, 'expired' => 'expired',
            OrderPaymentStatus::MANUAL_REVIEW, 'manual_review', 'pending_review' => 'manual_review',
            OrderPaymentStatus::CANCELLED, 'cancelled', 'canceled' => 'cancelled',
            default => 'pending',
        };
    }
}
