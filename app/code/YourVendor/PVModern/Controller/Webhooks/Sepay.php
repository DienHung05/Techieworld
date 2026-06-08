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


class Sepay implements HttpPostActionInterface, CsrfAwareActionInterface
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

        if (!$this->verifyRequest()) {
            return $result->setHttpResponseCode(401)
                ->setData(['success' => false, 'message' => 'Unauthorized']);
        }

        try {
            $payload = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return $result->setHttpResponseCode(400)
                ->setData(['success' => false, 'message' => 'Invalid JSON']);
        }

        if (!is_array($payload)) {
            return $result->setData(['success' => true, 'note' => 'Ignored: empty payload']);
        }

        
        
        $transferType = strtolower((string) ($payload['transferType'] ?? ''));
        if ($transferType !== 'in') {
            return $result->setData(['success' => true, 'note' => 'Ignored: not an incoming credit']);
        }

        $mapped = $this->mapSepayToProcessor($payload);
        $this->transactionProcessor->process($mapped, $raw, true);

        
        return $result->setData(['success' => true]);
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
        
        
        
        $liveSecret    = (string) $this->integrationConfig->getString('SEPAY_API_KEY');
        $sandboxSecret = (string) $this->integrationConfig->getString('SEPAY_SANDBOX_API_KEY');

        if ($liveSecret === '' && $sandboxSecret === '') {
            
            return $this->integrationConfig->getBool('SEPAY_SANDBOX_MODE', false);
        }

        $header = (string) $this->request->getHeader('Authorization');
        
        $token = preg_replace('/^(Apikey|Bearer)\s+/i', '', trim($header)) ?? '';

        
        if ($token === '') {
            $token = (string) (
                $this->request->getHeader('X-Webhook-Secret')
                ?: $this->request->getHeader('X-Sepay-Token')
                ?: ''
            );
        }

        if ($token === '') {
            return false;
        }
        if ($liveSecret !== '' && hash_equals($liveSecret, $token)) {
            return true;
        }
        if ($sandboxSecret !== '' && hash_equals($sandboxSecret, $token)) {
            return true;
        }
        return false;
    }

    
    private function mapSepayToProcessor(array $payload): array
    {
        return [
            
            
            'description' => trim(
                ((string) ($payload['content'] ?? ''))
                . ' '
                . ((string) ($payload['code'] ?? ''))
                . ' '
                . ((string) ($payload['description'] ?? ''))
            ),
            'amount' => (float) ($payload['transferAmount'] ?? 0),
            'id'     => (string) ($payload['id'] ?? ''),
            'tid'    => (string) ($payload['referenceCode'] ?? $payload['id'] ?? ''),
            'kind'   => 1, 
        ];
    }
}
