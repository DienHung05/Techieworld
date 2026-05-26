<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Cron;

use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\Payment\PaymentStatusPoller;

class PaymentFallbackPolling
{
    public function __construct(
        private readonly PaymentDb $paymentDb,
        private readonly PaymentStatusPoller $paymentStatusPoller,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->paymentDb->listPendingAttempts(120, 50) as $attempt) {
            try {
                $this->paymentStatusPoller->pollAttempt($attempt);
            } catch (\Throwable $exception) {
                $this->logger->warning('[PVModern][PaymentFallbackPolling] provider query failed', [
                    'attempt_id' => $attempt['id'] ?? null,
                    'provider' => $attempt['provider'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
