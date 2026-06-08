<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Webhooks;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\CassoTransactionProcessor;

class Casso implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $json,
        private readonly IntegrationConfig $integrationConfig,
        private readonly CassoTransactionProcessor $transactionProcessor
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $raw = (string) $this->request->getContent();
        $signatureVerified = $this->verifyRequest();
        if (!$signatureVerified) {
            return $result->setHttpResponseCode(401)->setData(['error' => 1, 'message' => 'Unauthorized']);
        }

        try {
            $payload = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return $result->setHttpResponseCode(400)->setData(['error' => 1, 'message' => 'Invalid JSON']);
        }

        $transactions = $this->extractTransactions(is_array($payload) ? $payload : []);
        foreach ($transactions as $transaction) {
            $this->transactionProcessor->process($transaction, $raw, true);
        }

        return $result->setData(['error' => 0, 'message' => 'ok']);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    private function verifyRequest(): bool
    {
        $config = $this->integrationConfig->getCassoConfig();
        $secret = (string) ($config['webhook_secret'] ?? '');

        if ($secret === '') {
            
            return !empty($config['sandbox_mode']);
        }

        
        
        
        
        
        
        $sig = (string) $this->request->getHeader('X-Casso-Signature');
        if ($sig !== '' && preg_match('/t=(\d+),v1=([a-f0-9]+)/i', $sig, $m)) {
            $timestamp = $m[1];
            $provided  = strtolower($m[2]);
            try {
                $decoded = $this->json->unserialize((string) $this->request->getContent());
            } catch (\Throwable) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                $canonical = json_encode(
                    $this->sortObjectKeysRecursively($decoded),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if (is_string($canonical)) {
                    $expected = hash_hmac('sha512', $timestamp . '.' . $canonical, $secret);
                    if (hash_equals($expected, $provided)) {
                        return true;
                    }
                }
            }
        }

        
        $sent = (string) (
            $this->request->getHeader('Secure-Token')
            ?: $this->request->getHeader('X-Webhook-Secret')
            ?: $this->request->getHeader('Authorization')
            ?: ''
        );
        $sent = preg_replace('/^Bearer\s+/i', '', trim($sent)) ?: '';

        return $sent !== '' && hash_equals($secret, $sent);
    }

    
    private function sortObjectKeysRecursively(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        if (array_is_list($data)) {
            return array_map(fn ($v) => $this->sortObjectKeysRecursively($v), $data);
        }
        $keys = array_keys($data);
        sort($keys);
        $sorted = [];
        foreach ($keys as $k) {
            $sorted[$k] = $this->sortObjectKeysRecursively($data[$k]);
        }
        return $sorted;
    }

    
    private function extractTransactions(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $isList = array_is_list($payload['data']);
            return $isList ? array_values(array_filter($payload['data'], 'is_array')) : [$payload['data']];
        }

        if (isset($payload['id']) || isset($payload['tid']) || isset($payload['transaction_id'])) {
            return [$payload];
        }

        return [];
    }
}
