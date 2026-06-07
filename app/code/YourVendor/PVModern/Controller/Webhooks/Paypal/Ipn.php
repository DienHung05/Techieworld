<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Webhooks\Paypal;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class Ipn implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly IntegrationConfig $integrationConfig,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache', true);

        try {
            $params = $this->request->getParams();
            $config = $this->integrationConfig->getPaypalConfig();
            $verified = $this->verifyIpn($params, (string) ($config['ipn_verify_url'] ?? ''));
            $incrementId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($params['invoice'] ?? $params['custom'] ?? '')) ?: '';
            $transactionId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($params['txn_id'] ?? '')) ?: '';
            $gross = (float) ($params['mc_gross'] ?? 0);
            $currency = strtoupper((string) ($params['mc_currency'] ?? ($config['currency'] ?? 'USD')));
            $rate = max(1.0, (float) ($config['vnd_to_usd_rate'] ?? 25000));
            $attempt = $this->paymentAttemptService->findAttemptForProvider(
                'paypal',
                $incrementId,
                '',
                $transactionId,
                $incrementId
            );
            $amount = $this->resolveVndAmount($attempt, $gross, $currency, $rate);
            $eventId = $this->paymentAttemptService->recordEvent(
                $attempt,
                'paypal',
                'ipn',
                $transactionId !== '' ? $transactionId : ($incrementId !== '' ? $incrementId : null),
                $transactionId !== '' ? $transactionId : null,
                $verified,
                $amount > 0 ? $amount : null,
                'VND',
                http_build_query($params)
            );

            if (!$verified) {
                $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Invalid PayPal IPN verification.');
                return $result->setHttpResponseCode(400)->setContents('INVALID');
            }

            if (!$attempt) {
                $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Payment attempt not found.');
                return $result->setHttpResponseCode(404)->setContents('NOT_FOUND');
            }

            $paypalStatus = strtolower((string) ($params['payment_status'] ?? ''));
            $providerStatus = in_array($paypalStatus, ['completed', 'processed'], true)
                ? 'success'
                : (in_array($paypalStatus, ['denied', 'failed', 'refunded', 'reversed', 'voided'], true) ? 'failed' : 'pending');
            $processResult = $this->paymentAttemptService->applyProviderResult(
                $attempt,
                $eventId,
                'paypal',
                $providerStatus,
                $transactionId !== '' ? $transactionId : $incrementId,
                $transactionId !== '' ? $transactionId : null,
                $amount,
                'VND',
                true
            );

            $this->logger->info('[PVModern][PayPal] IPN processed', [
                'increment_id' => $incrementId,
                'transaction_id' => $transactionId,
                'payment_status' => $paypalStatus,
                'result' => $processResult['result'] ?? '',
            ]);

            return $result->setContents('OK');
        } catch (\Throwable $exception) {
            $this->logger->error('[PVModern][PayPal] IPN error', [
                'message' => $exception->getMessage(),
            ]);
            return $result->setHttpResponseCode(500)->setContents('ERROR');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function verifyIpn(array $params, string $verifyUrl): bool
    {
        if ($verifyUrl === '' || !function_exists('curl_init')) {
            return false;
        }
        $payload = ['cmd' => '_notify-validate'] + $params;
        $ch = curl_init($verifyUrl);
        if (!$ch) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Techieworld PayPal IPN verifier',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return is_string($response) && trim($response) === 'VERIFIED';
    }

    /**
     * @param array<string, mixed>|null $attempt
     */
    private function resolveVndAmount(?array $attempt, float $gross, string $currency, float $rate): float
    {
        if ($currency === 'VND') {
            return $gross;
        }

        $converted = round($gross * $rate, 2);
        if (!$attempt) {
            return $converted;
        }

        $expectedVnd = (float) ($attempt['amount'] ?? 0);
        if ($expectedVnd <= 0) {
            return $converted;
        }

        $expectedForeign = round($expectedVnd / $rate, 2);
        if (abs(round($gross, 2) - $expectedForeign) <= 0.01) {
            return $expectedVnd;
        }

        return $converted;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
