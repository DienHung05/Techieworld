<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Webhooks\Vnpay;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class Ipn implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly IntegrationConfig $integrationConfig,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $params = $this->request->getParams();
        $signatureVerified = $this->verifySignature($params);
        $txnRef = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($params['vnp_TxnRef'] ?? '')) ?: '';
        $transactionNo = (string) ($params['vnp_TransactionNo'] ?? '');
        $amount = ((float) ($params['vnp_Amount'] ?? 0)) / 100;
        $attempt = $this->paymentAttemptService->findAttemptForProvider('vnpay', $txnRef, '', $transactionNo, $txnRef);
        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'vnpay',
            'ipn',
            (string) ($params['vnp_TransactionNo'] ?? $txnRef) ?: null,
            $transactionNo !== '' ? $transactionNo : null,
            $signatureVerified,
            $amount > 0 ? $amount : null,
            'VND',
            http_build_query($params)
        );

        if (!$signatureVerified) {
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Invalid VNPay signature.');
            return $result->setData(['RspCode' => '97', 'Message' => 'Invalid signature']);
        }

        if (!$attempt) {
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Payment attempt not found.');
            return $result->setData(['RspCode' => '01', 'Message' => 'Order not found']);
        }

        $responseCode = (string) ($params['vnp_ResponseCode'] ?? '');
        $transactionStatus = (string) ($params['vnp_TransactionStatus'] ?? $responseCode);
        $providerStatus = ($responseCode === '00' && $transactionStatus === '00') ? 'success' : 'failed';
        $processResult = $this->paymentAttemptService->applyProviderResult(
            $attempt,
            $eventId,
            'vnpay',
            $providerStatus,
            (string) ($params['vnp_TransactionNo'] ?? $txnRef) ?: null,
            $transactionNo !== '' ? $transactionNo : null,
            $amount,
            'VND',
            true
        );

        $this->logger->info('[PVModern][VNPay] IPN processed', [
            'txn_ref' => $txnRef,
            'transaction_no' => $transactionNo,
            'result' => $processResult['result'] ?? '',
        ]);

        if (($processResult['result'] ?? '') === 'manual_review') {
            return $result->setData(['RspCode' => '04', 'Message' => 'Amount invalid or review required']);
        }

        return $result->setData(['RspCode' => '00', 'Message' => 'Confirm Success']);
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
}
