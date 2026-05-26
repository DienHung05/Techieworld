<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\PaymentSessions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\SepayPoller;

class Events implements HttpGetActionInterface
{
    private const MAX_DURATION_SEC  = 25 * 60;
    // Internal DB-poll cadence — every 300ms we re-check pv_payment_attempt for
    // status changes (e.g. webhook just flipped it to paid). Keep this tight
    // because the webhook → DB flip → SSE push chain should feel instant.
    private const POLL_INTERVAL_US  = 150_000;
    private const KEEPALIVE_SEC     = 10;
    // Active SePay pull cadence — every 2s we call SePay's UserAPI for this
    // order's transfer code. This is the safety net for when SePay's webhook
    // delivery is delayed. 2s gives us ~5s worst-case detection (transfer →
    // SePay scrape ~3s → our pull → DB flip → SSE push).
    private const SEPAY_PULL_EVERY_SEC = 2;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly Json $json,
        private readonly PaymentDb $paymentDb,
        private readonly SepayPoller $sepayPoller,
        private readonly IntegrationConfig $integrationConfig
    ) {
    }

    public function execute()
    {
        // Magento wraps the controller call in its own output buffer. We must
        // tear ALL buffers down before sending headers, then bypass the
        // framework's response handler by exit()-ing once we're done streaming.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        @set_time_limit(0);
        ignore_user_abort(false);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: identity');
        header('Connection: keep-alive');

        $emit = static function (string $payload): void {
            echo $payload;
            @flush();
        };

        $emit(": connected\n\n");

        $deadline       = time() + self::MAX_DURATION_SEC;
        $lastKeepalive  = time();
        $lastSepayPull  = 0;
        $lastStatus     = null;
        $lastTxnId      = null;
        // Demo mode flips the order via the local ScanPaid controller — no
        // SePay API needs to be hit. Skipping the upstream HTTP poll removes
        // up-to-5s of network latency from every poll cycle and noticeably
        // speeds up the time between phone-scan and step 5.
        $isDemoMode     = $this->integrationConfig->getBool('PVMODERN_PAYMENT_DEMO', false);

        while (time() < $deadline && !connection_aborted()) {
            $attempt = $this->loadAttempt();
            $status  = $attempt ? (string) $attempt['status'] : 'unknown';
            $txnId   = $attempt ? (string) ($attempt['provider_transaction_id'] ?? '') : '';

            // Active pull from SePay UserAPI every N seconds, in case the
            // webhook never arrives (SePay's Free-tier webhook delivery is
            // unreliable for personal accounts). If SePay's list returns a
            // matching transaction we process it ourselves through the same
            // CassoTransactionProcessor — DB flips, next loop iteration emits
            // the paid frame.
            if (!$isDemoMode
                && $attempt && in_array($status, ['pending', 'awaiting_payment'], true)
                && time() - $lastSepayPull >= self::SEPAY_PULL_EVERY_SEC
            ) {
                $transferCode = (string) ($attempt['provider_order_id'] ?? '');
                if ($transferCode !== '') {
                    try {
                        $this->sepayPoller->pollForTransferCode(
                            $transferCode,
                            (int) round((float) ($attempt['amount'] ?? 0))
                        );
                    } catch (\Throwable) {
                        // Poll failures must NOT abort the SSE stream.
                    }
                }
                $lastSepayPull = time();
                // Reload attempt after poll so we emit immediately if the poll matched.
                $attempt = $this->loadAttempt();
                $status  = $attempt ? (string) $attempt['status'] : $status;
                $txnId   = $attempt ? (string) ($attempt['provider_transaction_id'] ?? '') : $txnId;
            }

            if ($status !== $lastStatus || $txnId !== $lastTxnId) {
                $payload = $this->buildPayload($attempt, $status);
                $emit("event: status\n" . 'data: ' . $this->json->serialize($payload) . "\n\n");
                $lastStatus = $status;
                $lastTxnId  = $txnId;

                if (in_array($status, ['paid', 'failed', 'cancelled', 'expired'], true)) {
                    exit;
                }
            }

            if (time() - $lastKeepalive >= self::KEEPALIVE_SEC) {
                $emit(": keepalive\n\n");
                $lastKeepalive = time();
            }

            usleep(self::POLL_INTERVAL_US);
        }

        exit;
    }

    /**
     * @param array<string, mixed>|null $attempt
     * @return array<string, mixed>
     */
    private function buildPayload(?array $attempt, string $status): array
    {
        if (!$attempt) {
            return [
                'success'         => false,
                'status'          => $status,
                'nextStepAllowed' => false,
            ];
        }

        return [
            'paymentAttemptId'      => (int) $attempt['id'],
            'orderId'               => (string) $attempt['magento_increment_id'],
            'method'                => (string) $attempt['provider'],
            'status'                => $status,
            'amount'                => (int) round((float) ($attempt['total_amount'] ?? 0)),
            'paidAt'                => !empty($attempt['paid_at']) ? gmdate('c', strtotime((string) $attempt['paid_at'])) : null,
            'providerTransactionId' => (string) ($attempt['provider_transaction_id'] ?? ''),
            'nextStepAllowed'       => $status === 'paid',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadAttempt(): ?array
    {
        $id = (int) ($this->request->getParam('paymentAttemptId') ?: $this->request->getParam('payment_attempt_id') ?: $this->request->getParam('id'));
        if ($id > 0) {
            return $this->paymentDb->findAttemptById($id);
        }
        $orderId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($this->request->getParam('orderId') ?: $this->request->getParam('order_id'))) ?: '';
        return $orderId !== '' ? $this->paymentDb->findLatestAttemptForIncrement($orderId) : null;
    }
}
