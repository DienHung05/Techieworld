<?php
declare(strict_types=1);
namespace YourVendor\PVModern\Controller\Payments;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\CassoTransactionProcessor;

class Casso implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Json $json,
        private readonly PaymentDb $paymentDb,
        private readonly IntegrationConfig $integrationConfig,
        private readonly CassoTransactionProcessor $transactionProcessor
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException { return null; }
    public function validateForCsrf(RequestInterface $request): ?bool { return true; }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        
        
        
        $configToken = (string)($this->integrationConfig->getCassoConfig()['webhook_secret'] ?? '')
            ?: (string)$this->deploymentConfig->get('pvmodern/casso_token', '');
        $sentToken = (string)($this->request->getHeader('Secure-Token')
            ?? $this->request->getHeader('x-api-key')
            ?? $this->request->getHeader('Authorization')
            ?? '');
        $sentToken = ltrim($sentToken, 'Bearer ');

        if ($configToken === '' || !hash_equals($configToken, $sentToken)) {
            return $result->setHttpResponseCode(401)->setData(['error' => 1, 'message' => 'Unauthorized']);
        }

        $body = $this->request->getContent();
        try {
            $payload = $this->json->unserialize($body);
        } catch (\Throwable $e) {
            return $result->setData(['error' => 0, 'message' => 'ok']); 
        }

        
        $transactions = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            $transactions = $payload['data'];
        } elseif (isset($payload['id'])) {
            $transactions = [$payload];
        }

        foreach ($transactions as $tx) {
            if (is_array($tx)) {
                $this->transactionProcessor->process($tx, $body, true);
            }
        }

        return $result->setData(['error' => 0, 'message' => 'ok']);
    }

    private function processCassoTransaction(array $tx, string $rawBody): void
    {
        $description = (string)($tx['description'] ?? $tx['memo'] ?? '');
        $amount = (float)($tx['amount'] ?? 0);
        $txId = (string)($tx['tid'] ?? $tx['transaction_id'] ?? $tx['id'] ?? '');
        $kind = (int)($tx['kind'] ?? 1); 

        if ($kind !== 1 || $amount <= 0) { return; } 

        
        $pvOrder = $this->findMatchByDescription($description, $amount);
        if (!$pvOrder) {
            
            $this->paymentDb->logVerification([
                'pv_order_id' => 0,
                'source' => 'casso_unmatched',
                'amount' => $amount,
                'transaction_id' => $txId,
                'raw_payload' => $rawBody,
                'note' => 'No matching order found for description: ' . substr($description, 0, 200),
            ]);
            return;
        }

        if ($pvOrder['payment_status'] === 'paid') { return; } 

        
        $this->paymentDb->updateOrder((int)$pvOrder['id'], [
            'payment_status' => 'paid',
            'current_step' => 5,
            'paid_at' => date('Y-m-d H:i:s'),
        ]);

        $this->paymentDb->logVerification([
            'pv_order_id' => (int)$pvOrder['id'],
            'source' => 'casso',
            'amount' => $amount,
            'transaction_id' => $txId,
            'raw_payload' => $rawBody,
            'note' => 'Auto-verified via Casso webhook. Amount: ' . $amount,
        ]);
    }

    private function findMatchByDescription(string $description, float $amount): ?array
    {
        
        if (preg_match('/\bORD\d+\b/i', $description, $m)) {
            $code = strtoupper($m[0]);
            $pvOrder = $this->paymentDb->findByTransferCode($code);
            if ($pvOrder && abs((float)$pvOrder['total_amount'] - $amount) < 1000) {
                return $pvOrder;
            }
        }
        
        if (preg_match('/\b1\d{8,9}\b/', $description, $m)) {
            $code = 'ORD' . $m[0];
            $pvOrder = $this->paymentDb->findByTransferCode($code);
            if ($pvOrder) return $pvOrder;
        }
        return null;
    }
}
