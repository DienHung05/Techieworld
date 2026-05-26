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

/**
 * SePay webhook receiver.
 *
 * SePay (sepay.vn) delivers a POST to this endpoint whenever a transaction
 * touches the linked bank account. Authentication is the simple "API Key"
 * mode: SePay sends "Authorization: Apikey <secret>" and we string-compare.
 *
 * Payload shape (incoming credit):
 * {
 *   "id": 92704,
 *   "gateway": "BIDV",
 *   "transactionDate": "2026-05-15 03:01:17",
 *   "accountNumber": "4661104867",
 *   "subAccount": "",
 *   "code": "ORD000000064",
 *   "content": "ORD000000064 thanh toan don hang",
 *   "transferType": "in",
 *   "description": "DIEN MANH HUNG chuyen tien",
 *   "transferAmount": 113006,
 *   "accumulated": 702015,
 *   "referenceCode": "FT26135064074465"
 * }
 *
 * SePay requires our response to be HTTP 200 + {"success": true} within 30s
 * or it will retry with exponential backoff.
 */
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

        // Only process incoming credit transactions; ignore "out" (we sent money)
        // and any non-credit events.
        $transferType = strtolower((string) ($payload['transferType'] ?? ''));
        if ($transferType !== 'in') {
            return $result->setData(['success' => true, 'note' => 'Ignored: not an incoming credit']);
        }

        $mapped = $this->mapSepayToProcessor($payload);
        $this->transactionProcessor->process($mapped, $raw, true);

        // SePay expects exactly this shape — anything else triggers a retry.
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
        // SePay can deliver from TWO independent environments — Live and
        // Sandbox — each with its own API Key. We accept either, so the same
        // /api/webhooks/sepay endpoint serves both without code branching.
        $liveSecret    = (string) $this->integrationConfig->getString('SEPAY_API_KEY');
        $sandboxSecret = (string) $this->integrationConfig->getString('SEPAY_SANDBOX_API_KEY');

        if ($liveSecret === '' && $sandboxSecret === '') {
            // Neither key configured — only allow if explicit dev bypass is on.
            return $this->integrationConfig->getBool('SEPAY_SANDBOX_MODE', false);
        }

        $header = (string) $this->request->getHeader('Authorization');
        // Accept both "Apikey <secret>" (SePay default) and "Bearer <secret>".
        $token = preg_replace('/^(Apikey|Bearer)\s+/i', '', trim($header)) ?? '';

        // Some integrators also forward the secret as X-Webhook-Secret / X-Sepay-Token.
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

    /**
     * Translate SePay's payload shape into the field names CassoTransactionProcessor expects.
     * Processor reads: description|memo|content, amount, tid|transaction_id|id, kind (1 = credit).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mapSepayToProcessor(array $payload): array
    {
        return [
            // Order memo lives in `content`; SePay also exposes `code` (their own short ref)
            // and `description` (counterparty name). Concatenate so the ORD\d+ regex catches it.
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
            'kind'   => 1, // already filtered to "in" above
        ];
    }
}
