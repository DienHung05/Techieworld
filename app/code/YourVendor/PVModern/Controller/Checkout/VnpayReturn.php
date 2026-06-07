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
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class VnpayReturn implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly IntegrationConfig $integrationConfig,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $params = $this->request->getParams();
        $isValid = $this->verifySignature($params);
        $responseCode = (string) ($params['vnp_ResponseCode'] ?? '');
        $transactionStatus = (string) ($params['vnp_TransactionStatus'] ?? $responseCode);
        $txnRef = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($params['vnp_TxnRef'] ?? '')) ?: '';
        $isPaidResponse = $isValid && $responseCode === '00' && $transactionStatus === '00';
        $processResult = '';

        if ($isValid && $txnRef !== '') {
            $processResult = $this->applyVerifiedReturn($params, $txnRef, $responseCode, $transactionStatus);
        }

        $isConfirmed = $isPaidResponse && in_array($processResult, ['accepted', 'duplicate'], true);

        $this->logger->info('[PVModern][VNPay] return received', [
            'valid' => $isValid,
            'response_code' => $responseCode,
            'transaction_status' => $transactionStatus,
            'txn_ref' => $txnRef,
            'process_result' => $processResult,
            'note' => 'Verified VNPay return updates payment immediately; IPN remains idempotent if it arrives later.',
        ]);

        $redirectPath = $txnRef !== '' ? 'payment-confirmation' : 'checkout';
        $query = [
            'payment_result' => $isConfirmed ? 'success' : ($isPaidResponse ? 'pending' : 'failed'),
            'gateway' => 'vnpay',
        ];
        if ($txnRef !== '') {
            $query['orderId'] = $txnRef;
            $query['txn'] = $txnRef;
        }

        return $this->redirectFactory->create()->setPath($redirectPath, ['_query' => $query]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyVerifiedReturn(array $payload, string $txnRef, string $responseCode, string $transactionStatus): string
    {
        $transactionNo = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['vnp_TransactionNo'] ?? '')) ?: '';
        $amount = ((float) ($payload['vnp_Amount'] ?? 0)) / 100;
        $attempt = $this->paymentAttemptService->findAttemptForProvider(
            'vnpay',
            $txnRef,
            '',
            $transactionNo,
            $txnRef
        );
        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'vnpay',
            'return',
            $transactionNo !== '' ? $transactionNo : $txnRef,
            $transactionNo !== '' ? $transactionNo : null,
            true,
            $amount > 0 ? $amount : null,
            'VND',
            http_build_query($payload)
        );

        if (!$attempt) {
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Payment attempt not found on VNPay return.');
            return 'not_found';
        }

        $providerStatus = ($responseCode === '00' && $transactionStatus === '00') ? 'success' : 'failed';
        $processResult = $this->paymentAttemptService->applyProviderResult(
            $attempt,
            $eventId,
            'vnpay',
            $providerStatus,
            $transactionNo !== '' ? $transactionNo : $txnRef,
            $transactionNo !== '' ? $transactionNo : null,
            $amount,
            'VND',
            true
        );

        return (string) ($processResult['result'] ?? '');
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
                $pairs[] = urlencode((string) $key) . '=' . urlencode((string) $value);
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
