<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Cron;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\CassoTransactionProcessor;

class BankTransferReconciliation
{
    public function __construct(
        private readonly IntegrationConfig $integrationConfig,
        private readonly CassoTransactionProcessor $transactionProcessor,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $config = $this->integrationConfig->getCassoConfig();
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            return;
        }

        $endpoint = rtrim((string) $config['api_base_url'], '/') . '/transactions?page=1&pageSize=50';
        $payload = $this->getJson($endpoint, $apiKey);
        if (!$payload) {
            return;
        }

        $transactions = $this->extractTransactions($payload);
        $raw = $this->json->serialize($payload);
        foreach ($transactions as $transaction) {
            try {
                $this->transactionProcessor->process($transaction, $raw, true);
            } catch (\Throwable $exception) {
                $this->logger->warning('[PVModern][BankTransferReconciliation] transaction processing failed', [
                    'transaction_id' => $transaction['id'] ?? $transaction['tid'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    
    private function getJson(string $url, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Apikey ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $this->logger->warning('[PVModern][BankTransferReconciliation] Casso request failed', ['error' => curl_error($ch)]);
        }
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    
    private function extractTransactions(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        if (isset($data['records']) && is_array($data['records'])) {
            return array_values(array_filter($data['records'], 'is_array'));
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (isset($data['id']) || isset($data['tid']) || isset($data['transaction_id'])) {
            return [$data];
        }
        return [];
    }
}
