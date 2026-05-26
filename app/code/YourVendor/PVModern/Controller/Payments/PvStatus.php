<?php
declare(strict_types=1);
namespace YourVendor\PVModern\Controller\Payments;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Helper\PaymentDb;

class PvStatus implements HttpGetActionInterface
{
    // Rate limiting: track last poll time per orderId in APCu or skip gracefully
    private static array $lastPoll = [];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly PaymentDb $paymentDb,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Json $json
    ) {}

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache', true);

        $rawId = trim((string)($this->request->getParam('orderId') ?? $this->request->getParam('order_id') ?? ''));
        // Keep raw form: strip non-alphanumeric but preserve leading zeros (Magento uses zero-padded IDs like 000000037)
        $incrementId = preg_replace('/[^A-Za-z0-9]/', '', $rawId);
        if ($incrementId === '' || $incrementId === '0') {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'Missing orderId']);
        }

        // Rate limit: 1 req / 3 sec per order (APCu optional)
        $rlKey = 'pvpoll_' . md5($incrementId);
        if (function_exists('apcu_fetch')) {
            apcu_store($rlKey, 1, 3);
        }

        // Find or create pv_payment_order — try exact match first, then numeric-only match
        $pvOrder = $this->paymentDb->findByIncrementId($incrementId);

        if (!$pvOrder) {
            // Look up Magento order — match by exact ID or by numeric value (handles leading zeros)
            $orders = $this->orderCollectionFactory->create()
                ->addFieldToFilter('increment_id', ['in' => [$incrementId, ltrim($incrementId, '0') ?: '0']])
                ->setPageSize(1);
            $magentoOrder = $orders->getFirstItem();

            if (!$magentoOrder || !$magentoOrder->getId()) {
                return $result->setHttpResponseCode(404)->setData(['success' => false, 'message' => 'Order not found']);
            }

            // Use the actual increment_id from the order (preserves leading zeros)
            $actualIncrementId = (string)$magentoOrder->getIncrementId();

            // Map payment method from Magento payment method code
            $magentoMethod = (string)$magentoOrder->getPayment()->getMethod();
            $pvMethod = $this->mapMagentoMethod($magentoMethod, $magentoOrder->getPayment()->getAdditionalInformation());

            $transferCode = $this->paymentDb->generateTransferCode($actualIncrementId);
            $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 min

            $pvOrderId = $this->paymentDb->createOrder([
                'magento_increment_id' => $actualIncrementId,
                'transfer_code' => $transferCode,
                'customer_name' => (string)$magentoOrder->getCustomerName(),
                'customer_email' => (string)$magentoOrder->getCustomerEmail(),
                'customer_phone' => (string)($magentoOrder->getShippingAddress() ? $magentoOrder->getShippingAddress()->getTelephone() : ''),
                'total_amount' => (float)$magentoOrder->getGrandTotal(),
                'payment_method' => $pvMethod,
                'payment_status' => 'pending',
                'current_step' => 4,
                'expires_at' => $expiresAt,
            ]);
            $pvOrder = $this->paymentDb->findById($pvOrderId);
        }

        // Auto-expire check
        if (in_array($pvOrder['payment_status'], ['pending', 'pending_review'], true)
            && strtotime($pvOrder['expires_at']) < time()) {
            $this->paymentDb->updateOrder((int)$pvOrder['id'], ['payment_status' => 'expired']);
            $pvOrder['payment_status'] = 'expired';
        }

        $attempt = $this->paymentDb->findLatestAttemptForIncrement((string) $pvOrder['magento_increment_id']);
        $status = (string) ($pvOrder['payment_status'] ?? 'pending');
        if ($attempt && in_array((string) $attempt['status'], ['paid', 'failed', 'expired', 'manual_review'], true)) {
            $status = (string) $attempt['status'];
        }

        $paymentContext = $this->loadPaymentContext((string) $pvOrder['magento_increment_id']);

        return $result->setData([
            'success' => true,
            'pv_order_id' => (int)$pvOrder['id'],
            'paymentAttemptId' => $attempt ? (int) $attempt['id'] : (int) ($pvOrder['payment_attempt_id'] ?? 0),
            'payment_attempt_id' => $attempt ? (int) $attempt['id'] : (int) ($pvOrder['payment_attempt_id'] ?? 0),
            'orderId' => (string) $pvOrder['magento_increment_id'],
            'method' => (string) $pvOrder['payment_method'],
            'status' => $status,
            'paidAt' => !empty($pvOrder['paid_at']) ? gmdate('c', strtotime((string) $pvOrder['paid_at'])) : null,
            'providerTransactionId' => (string) ($pvOrder['provider_transaction_id'] ?? ($attempt['provider_transaction_id'] ?? '')),
            'transfer_code' => $pvOrder['transfer_code'],
            'payment_method' => $pvOrder['payment_method'],
            'total_amount' => (float)$pvOrder['total_amount'],
            'expires_at' => $pvOrder['expires_at'],
            'screenshot_uploaded' => !empty($pvOrder['screenshot_url']),
            'nextStepAllowed' => $status === 'paid',
            'can_proceed' => $status === 'paid',
            'payment' => $paymentContext,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPaymentContext(string $incrementId): ?array
    {
        try {
            $orders = $this->orderCollectionFactory->create()
                ->addFieldToFilter('increment_id', $incrementId)
                ->setPageSize(1);
            $order = $orders->getFirstItem();
            if (!$order || !$order->getId() || !$order->getPayment()) {
                return null;
            }
            $raw = (string) $order->getPayment()->getAdditionalInformation('pvmodern_payment_context');
            if ($raw === '') {
                return null;
            }
            $decoded = $this->json->unserialize($raw);
            if (!is_array($decoded)) {
                return null;
            }
            $decoded['amount'] = (int) round((float) ($decoded['amount'] ?? $order->getGrandTotal()));
            return $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mapMagentoMethod(string $method, array $addInfo): string
    {
        // wallet_id is the UI payment method key (momo/vnpay/bank_qr/card) stored directly
        $walletId = strtolower((string)($addInfo['wallet_id'] ?? ''));
        if (in_array($walletId, ['momo', 'vnpay', 'bank_qr', 'card'], true)) {
            return $walletId;
        }
        // gateway_channel fallback
        $channel = strtolower((string)($addInfo['gateway_channel'] ?? ''));
        if ($channel === 'momo') return 'momo';
        if ($channel === 'vnpay') return 'vnpay';
        if ($channel === 'bank_qr') return 'bank_qr';
        if ($channel === 'card') return 'card';
        if (str_contains($method, 'banktransfer')) return 'bank_qr';
        return 'bank_qr';
    }
}
