<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment;

use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;

/**
 * Active pull of recent SePay transactions for a specific account + memo.
 *
 * Why this exists: SePay's webhook delivery on personal-account Free tier is
 * unreliable / delayed. The webhook path still works when it fires, but as a
 * resilience net the SSE stream also actively pulls SePay's UserAPI for
 * matching transactions. The same CassoTransactionProcessor handles webhook
 * AND poll-discovered transactions identically — so any transaction that
 * SePay confirms reaches our DB within seconds whether they pushed or we
 * pulled.
 *
 * Docs: https://docs.sepay.vn/api-giao-dich.html
 *       GET https://my.sepay.vn/userapi/transactions/list
 */
class SepayPoller
{
    private const API_BASE = 'https://my.sepay.vn/userapi';
    private const FETCH_TIMEOUT_SEC = 5;

    public function __construct(
        private readonly IntegrationConfig $integrationConfig,
        private readonly CassoTransactionProcessor $transactionProcessor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Look for a transaction matching the given transfer code (`ORD<digits>`)
     * on the configured BIDV account, processed through the standard pipeline.
     * Returns the processor result, or null if nothing matched / nothing to do.
     *
     * @return array<string, mixed>|null
     */
    public function pollForTransferCode(string $transferCode, ?int $expectedAmount = null): ?array
    {
        $token = trim((string) $this->integrationConfig->getString('SEPAY_API_TOKEN'));
        if ($token === '') {
            return null;
        }
        if ($transferCode === '' || !preg_match('/^ORD[0-9]+$/i', $transferCode)) {
            return null;
        }

        $details = $this->integrationConfig->getBankTransferDetails();
        $accountNumber = (string) ($details['account_number'] ?? '');
        if ($accountNumber === '') {
            return null;
        }

        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $url = self::API_BASE . '/transactions/list?' . http_build_query([
            'account_number'        => $accountNumber,
            'transaction_date_min'  => $yesterday,
            'transaction_date_max'  => $today,
            'limit'                 => 50,
        ]);

        $response = $this->httpGet($url, $token);
        if ($response === null) {
            return null;
        }

        $transactions = $response['transactions'] ?? [];
        if (!is_array($transactions) || empty($transactions)) {
            return null;
        }

        foreach ($transactions as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $content = (string) (
                ($tx['transaction_content'] ?? '')
                . ' '
                . ($tx['code'] ?? '')
            );
            if (stripos($content, $transferCode) === false) {
                continue;
            }
            $amountIn = (float) ($tx['amount_in'] ?? 0);
            if ($amountIn <= 0) {
                // Outgoing transfer — ignore
                continue;
            }
            // Map to the shape CassoTransactionProcessor expects.
            $mapped = [
                'description' => $content,
                'amount'      => $amountIn,
                'id'          => (string) ($tx['id'] ?? ''),
                'tid'         => (string) ($tx['reference_number'] ?? $tx['id'] ?? ''),
                'kind'        => 1,
            ];
            $raw = json_encode(['source' => 'sepay_poll', 'transaction' => $tx], JSON_UNESCAPED_UNICODE);
            return $this->transactionProcessor->process($mapped, $raw ?: '', true);
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpGet(string $url, string $bearer): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $bearer,
                'Accept: application/json',
                'User-Agent: PVModern-Magento/SepayPoller/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($status !== 200 || !is_string($body) || $body === '') {
            if ($err !== '') {
                $this->logger->warning('[SepayPoller] HTTP error: ' . $err);
            } elseif ($status !== 200) {
                $this->logger->warning('[SepayPoller] HTTP status ' . $status . ' body: ' . substr((string) $body, 0, 200));
            }
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
