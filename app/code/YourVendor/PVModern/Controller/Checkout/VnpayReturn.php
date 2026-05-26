<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\Checkout\OrderPaymentStatus;
use YourVendor\PVModern\Model\IntegrationConfig;

class VnpayReturn implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly IntegrationConfig $integrationConfig,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $params = $this->request->getParams();
        $isValid = $this->verifySignature($params);
        $isPaid = $isValid && (($params['vnp_ResponseCode'] ?? '') === '00');

        $this->logger->info('[PVModern][VNPay] return received', [
            'valid' => $isValid,
            'response_code' => $params['vnp_ResponseCode'] ?? null,
            'txn_ref' => $params['vnp_TxnRef'] ?? null,
            'note' => 'Browser return is navigation-only; payment confirmation waits for verified IPN.',
        ]);

        return $this->redirectFactory->create()->setPath('checkout', [
            '_query' => [
                'payment_result' => $isPaid ? 'pending' : 'failed',
                'gateway' => 'vnpay',
                'txn' => (string) ($params['vnp_TxnRef'] ?? ''),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function verifySignature(array $params): bool
    {
        $secret = (string) ($this->integrationConfig->getVnpayConfig()['hash_secret'] ?? '');
        $secureHash = (string) ($params['vnp_SecureHash'] ?? '');
        if ($secret === '' || $secureHash === '') {
            return false;
        }

        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if (str_starts_with((string) $key, 'vnp_') && $value !== '' && $value !== null) {
                $pairs[] = (string) $key . '=' . urlencode((string) $value);
            }
        }

        $expected = hash_hmac('sha512', implode('&', $pairs), $secret);
        return hash_equals(strtolower($expected), strtolower($secureHash));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateOrderPayment(array $payload, bool $isPaid): void
    {
        $incrementId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['vnp_TxnRef'] ?? '')) ?: '';
        if ($incrementId === '') {
            return;
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId);
        $collection->setPageSize(1);
        $order = $collection->getFirstItem();
        if (!$order || !$order->getId() || !$order->getPayment()) {
            return;
        }

        $status = $isPaid ? OrderPaymentStatus::PAID : OrderPaymentStatus::FAILED;
        $payment = $order->getPayment();
        $payment->setAdditionalInformation('pvmodern_payment_status', $status);
        $payment->setAdditionalInformation('pvmodern_payment_gateway', 'vnpay');
        $payment->setAdditionalInformation('pvmodern_payment_transaction_id', (string) ($payload['vnp_TransactionNo'] ?? ''));
        $order->addCommentToStatusHistory(
            sprintf('VNPay return verified. Payment status: %s. Transaction: %s', $status, (string) ($payload['vnp_TransactionNo'] ?? ''))
        );
        $this->orderRepository->save($order);
    }
}
