<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Cron;

use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class PaymentExpiration
{
    public function __construct(
        private readonly PaymentDb $paymentDb,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->paymentDb->listExpiredPendingAttempts() as $attempt) {
            try {
                $this->paymentAttemptService->expireAttempt($attempt);
            } catch (\Throwable $exception) {
                $this->logger->error('[PVModern][PaymentExpiration] failed', [
                    'attempt_id' => $attempt['id'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
