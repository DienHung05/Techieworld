<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment;

use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Helper\PaymentDb;

class CassoTransactionProcessor
{
    public function __construct(
        private readonly PaymentDb $paymentDb,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly Json $json
    ) {
    }

    
    public function process(array $transaction, string $rawPayload, bool $signatureVerified): array
    {
        $description = (string) ($transaction['description'] ?? $transaction['memo'] ?? $transaction['content'] ?? '');
        $amount = (float) ($transaction['amount'] ?? 0);
        $transactionId = (string) ($transaction['tid'] ?? $transaction['transaction_id'] ?? $transaction['id'] ?? '');
        $kind = (int) ($transaction['kind'] ?? 1);

        if (!$signatureVerified) {
            $eventId = $this->paymentAttemptService->recordEvent(
                null,
                'casso',
                'bank_transaction',
                $transactionId !== '' ? $transactionId : null,
                $transactionId !== '' ? $transactionId : null,
                false,
                $amount > 0 ? $amount : null,
                'VND',
                $rawPayload,
                'rejected',
                'Invalid Casso webhook signature.'
            );
            $this->paymentAttemptService->finishEvent($eventId, 'rejected', 'Invalid Casso webhook signature.');
            return ['result' => 'rejected', 'message' => 'Invalid signature'];
        }

        if ($kind !== 1 || $amount <= 0) {
            return ['result' => 'ignored', 'message' => 'Not an incoming credit transaction.'];
        }

        if ($transactionId !== '' && $this->paymentDb->hasAcceptedEvent('casso', $transactionId, $transactionId)) {
            return ['result' => 'duplicate', 'message' => 'Duplicate Casso transaction.'];
        }

        $transferCode = $this->extractTransferCode($description);
        
        
        
        
        
        
        $attempt = null;
        if ($transferCode !== '') {
            foreach (['bank_transfer', 'momo', 'vnpay', 'stripe'] as $candidateProvider) {
                $found = $this->paymentAttemptService->findAttemptForProvider($candidateProvider, $transferCode);
                if ($found) {
                    $attempt = $found;
                    break;
                }
            }
        }

        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'casso',
            'bank_transaction',
            $transactionId !== '' ? $transactionId : null,
            $transactionId !== '' ? $transactionId : null,
            true,
            $amount,
            'VND',
            $rawPayload
        );

        if (!$attempt) {
            $this->createReviewOnce($transactionId, $amount, $description, null, 'unmatched', 'No exact transfer code match.', $transaction, $rawPayload);
            $this->paymentAttemptService->finishEvent($eventId, 'manual_review', 'No exact transfer code match.');
            return ['result' => 'manual_review', 'message' => 'No exact transfer code match.'];
        }

        
        
        $expected = round((float) ($attempt['amount'] ?? 0));
        $received = round((float) $amount);
        if (abs($expected - $received) > 0.5) {
            $this->paymentAttemptService->moveToManualReview(
                $attempt,
                'Casso amount mismatch. Expected ' . $expected . ', received ' . $amount . '.'
            );
            $this->createReviewOnce(
                $transactionId,
                $amount,
                $description,
                (int) ($attempt['pv_order_id'] ?? 0),
                'suggested',
                'Transfer code matched but amount was wrong.',
                $transaction,
                $rawPayload
            );
            $this->paymentAttemptService->finishEvent($eventId, 'manual_review', 'Amount mismatch.');
            return ['result' => 'manual_review', 'message' => 'Amount mismatch.'];
        }

        return $this->paymentAttemptService->applyProviderResult(
            $attempt,
            $eventId,
            'casso',
            'success',
            $transactionId !== '' ? $transactionId : null,
            $transactionId !== '' ? $transactionId : null,
            $amount,
            'VND',
            true
        );
    }

    private function extractTransferCode(string $description): string
    {
        if (preg_match('/\bORD[0-9]{4,}\b/i', $description, $matches)) {
            return strtoupper($matches[0]);
        }

        return '';
    }

    
    private function createReviewOnce(
        string $transactionId,
        float $amount,
        string $description,
        ?int $matchedOrderId,
        string $status,
        string $reason,
        array $transaction,
        string $rawPayload
    ): void {
        if ($transactionId !== '' && $this->paymentDb->findReviewByTransactionId($transactionId)) {
            return;
        }

        $time = (string) ($transaction['when'] ?? $transaction['transaction_time'] ?? $transaction['created_at'] ?? '');
        $timestamp = $time !== '' ? strtotime($time) : false;
        $this->paymentDb->createReview([
            'casso_transaction_id' => $transactionId,
            'amount' => $amount,
            'description' => $description,
            'transaction_time' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
            'matched_order_id' => $matchedOrderId,
            'status' => $status,
            'review_reason' => $reason,
            'raw_payload' => $rawPayload !== '' ? $rawPayload : $this->json->serialize($transaction),
        ]);
    }
}
