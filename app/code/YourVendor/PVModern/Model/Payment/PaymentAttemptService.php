<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\Checkout\OrderPaymentStatus;
use YourVendor\PVModern\Model\Shipping\ShippingManager;

class PaymentAttemptService
{
    public function __construct(
        private readonly PaymentDb $paymentDb,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ShippingManager $shippingManager,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $paymentInit
     * @return array<string, mixed>
     */
    public function registerAttemptForOrder(Order $order, string $provider, array $paymentInit, string $frontendMethod): array
    {
        $pvOrder = $this->ensurePvOrderForMagentoOrder($order, $frontendMethod);
        $incrementId = (string) $order->getIncrementId();
        $amount = (float) $order->getGrandTotal();
        $expiresAt = $this->normalizeDate((string) ($paymentInit['expires_at'] ?? ''), time() + 1800);
        $providerOrderId = (string) ($paymentInit['provider_order_id'] ?? $paymentInit['reference'] ?? $incrementId);
        $providerSessionId = (string) ($paymentInit['provider_session_id'] ?? '');
        $providerTransactionId = (string) ($paymentInit['provider_transaction_id'] ?? '');
        $paymentUrl = (string) ($paymentInit['paymentUrl'] ?? $paymentInit['payment_url'] ?? $paymentInit['redirect_url'] ?? '');
        $qrCodeUrl = (string) ($paymentInit['qrCodeUrl'] ?? $paymentInit['qr_code_url'] ?? '');
        $qrPayload = (string) ($paymentInit['qrPayload'] ?? $paymentInit['qr_payload'] ?? $paymentUrl);
        $rawCreateResponse = $this->serializeSafe($paymentInit['gateway_response'] ?? $paymentInit['raw_create_response'] ?? $paymentInit);

        $attemptId = $this->paymentDb->createAttempt([
            'pv_order_id' => (int) $pvOrder['id'],
            'magento_increment_id' => $incrementId,
            'provider' => $provider,
            'provider_order_id' => $providerOrderId,
            'provider_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => 'VND',
            'checkout_url' => $paymentUrl,
            'payment_url' => $paymentUrl,
            'qr_code_url' => $qrCodeUrl,
            'qr_code_payload' => $qrPayload,
            'deeplink_url' => (string) ($paymentInit['deeplink_url'] ?? ''),
            'provider_session_id' => $providerSessionId !== '' ? $providerSessionId : null,
            'client_secret' => (string) ($paymentInit['client_secret'] ?? ''),
            'raw_create_response' => $rawCreateResponse,
            'expires_at' => $expiresAt,
        ]);

        $this->paymentDb->updateOrder((int) $pvOrder['id'], [
            'payment_attempt_id' => $attemptId,
            'payment_method' => $frontendMethod,
            'payment_status' => 'pending',
            'provider_order_id' => $providerOrderId,
            'provider_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
            'provider_session_id' => $providerSessionId !== '' ? $providerSessionId : null,
            'total_amount' => $amount,
            'currency' => 'VND',
            'checkout_url' => $paymentUrl,
            'payment_url' => $paymentUrl,
            'qr_code_url' => $qrCodeUrl,
            'qr_code_payload' => $qrPayload,
            'deeplink_url' => (string) ($paymentInit['deeplink_url'] ?? ''),
            'raw_create_response' => $rawCreateResponse,
            'expires_at' => $expiresAt,
            'last_status_change_at' => date('Y-m-d H:i:s'),
        ]);

        $statusEndpoint = '/api/paymentSessions/status?paymentAttemptId=' . $attemptId;
        $eventsEndpoint = '/api/paymentSessions/events?paymentAttemptId=' . $attemptId;

        return $paymentInit + [
            'paymentAttemptId' => $attemptId,
            'payment_attempt_id' => $attemptId,
            'pv_order_id' => (int) $pvOrder['id'],
            'provider_order_id' => $providerOrderId,
            'provider_session_id' => $providerSessionId,
            'statusEndpoint' => $statusEndpoint,
            'status_endpoint' => $statusEndpoint,
            'eventsEndpoint' => $eventsEndpoint,
            'events_endpoint' => $eventsEndpoint,
            'expires_at' => $expiresAt,
            'expiresAt' => gmdate('c', strtotime($expiresAt)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ensurePvOrderForMagentoOrder(Order $order, string $frontendMethod): array
    {
        $incrementId = (string) $order->getIncrementId();
        $pvOrder = $this->paymentDb->findByIncrementId($incrementId);
        if ($pvOrder) {
            return $pvOrder;
        }

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $transferCode = $this->paymentDb->generateTransferCode($incrementId);
        $pvOrderId = $this->paymentDb->createOrder([
            'magento_increment_id' => $incrementId,
            'transfer_code' => $transferCode,
            'customer_name' => (string) $order->getCustomerName(),
            'customer_email' => (string) $order->getCustomerEmail(),
            'customer_phone' => (string) (($shipping ?: $billing) ? ($shipping ?: $billing)->getTelephone() : ''),
            'total_amount' => (float) $order->getGrandTotal(),
            'payment_method' => $frontendMethod,
            'payment_status' => 'pending',
            'currency' => 'VND',
            'current_step' => 4,
            'expires_at' => date('Y-m-d H:i:s', time() + 1800),
            'last_status_change_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->paymentDb->findById($pvOrderId) ?: ['id' => $pvOrderId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAttemptForProvider(
        string $provider,
        string $providerOrderId = '',
        string $providerSessionId = '',
        string $providerTransactionId = '',
        string $incrementId = ''
    ): ?array {
        $attempt = $this->paymentDb->findAttemptByProviderOrderId($provider, $providerOrderId)
            ?: $this->paymentDb->findAttemptByProviderSessionId($provider, $providerSessionId)
            ?: $this->paymentDb->findAttemptByProviderTransactionId($provider, $providerTransactionId);

        if (!$attempt && $incrementId !== '') {
            $candidate = $this->paymentDb->findLatestAttemptForIncrement($incrementId);
            if ($candidate && (string) ($candidate['provider'] ?? '') === $provider) {
                $attempt = $candidate;
            }
        }

        return $attempt;
    }

    /**
     * @param array<string, mixed>|null $attempt
     */
    public function recordEvent(
        ?array $attempt,
        string $provider,
        string $eventType,
        ?string $providerEventId,
        ?string $providerTransactionId,
        bool $signatureVerified,
        ?float $amount,
        string $currency,
        string $rawPayload,
        string $processingResult = 'received',
        string $errorMessage = ''
    ): int {
        return $this->paymentDb->logPaymentEvent([
            'payment_attempt_id' => $attempt ? (int) $attempt['id'] : 0,
            'pv_order_id' => $attempt ? (int) $attempt['pv_order_id'] : 0,
            'magento_increment_id' => $attempt ? (string) $attempt['magento_increment_id'] : '',
            'provider' => $provider,
            'event_type' => $eventType,
            'provider_event_id' => $providerEventId ?: null,
            'provider_transaction_id' => $providerTransactionId ?: null,
            'signature_verified' => $signatureVerified ? 1 : 0,
            'amount' => $amount,
            'currency' => strtoupper($currency ?: 'VND'),
            'raw_payload' => $rawPayload,
            'processing_result' => $processingResult,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
        ]);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public function applyProviderResult(
        array $attempt,
        int $eventId,
        string $provider,
        string $providerStatus,
        ?string $providerEventId,
        ?string $providerTransactionId,
        float $amount,
        string $currency,
        bool $signatureVerified
    ): array {
        if (!$signatureVerified) {
            $this->finishEvent($eventId, 'rejected', 'Invalid provider signature.');
            return ['result' => 'rejected', 'message' => 'Invalid provider signature.'];
        }

        $attempt = $this->paymentDb->findAttemptById((int) $attempt['id']) ?: $attempt;
        // VND has no sub-unit; compare rounded integers so 113006.20 vs 113006 isn't a mismatch.
        $expected = round((float) ($attempt['amount'] ?? 0));
        $received = round((float) $amount);
        if (abs($expected - $received) > 0.5) {
            $this->moveToManualReview($attempt, 'Amount mismatch. Expected ' . $expected . ', received ' . $received . '.');
            $this->finishEvent($eventId, 'manual_review', 'Amount mismatch.');
            return ['result' => 'manual_review', 'message' => 'Amount mismatch.'];
        }

        $pvOrder = $this->paymentDb->findById((int) $attempt['pv_order_id']);
        if (!$pvOrder) {
            $this->finishEvent($eventId, 'rejected', 'Local payment order not found.');
            return ['result' => 'rejected', 'message' => 'Payment order not found.'];
        }

        if (($pvOrder['payment_status'] ?? '') === 'paid' || ($attempt['status'] ?? '') === 'paid') {
            $this->finishEvent($eventId, 'duplicate');
            return ['result' => 'duplicate', 'message' => 'Already paid.'];
        }

        if (($pvOrder['payment_status'] ?? '') === 'expired' || ($attempt['status'] ?? '') === 'expired') {
            $this->moveToManualReview($attempt, 'Provider reported payment after local expiration.');
            $this->finishEvent($eventId, 'manual_review', 'Payment arrived after expiration.');
            return ['result' => 'manual_review', 'message' => 'Expired payment requires review.'];
        }

        $normalizedStatus = strtolower($providerStatus);
        if (in_array($normalizedStatus, ['success', 'paid', 'succeeded', '00', '0'], true)) {
            return $this->confirmPaid($attempt, $eventId, $provider, $providerTransactionId ?: '', $amount, $currency);
        }

        if (in_array($normalizedStatus, ['failed', 'cancelled', 'canceled', '24', 'payment_failed'], true)) {
            $this->markFailed($attempt, $providerTransactionId ?: '', 'Provider status: ' . $providerStatus);
            $this->finishEvent($eventId, 'accepted');
            return ['result' => 'accepted', 'message' => 'Payment failed status recorded.'];
        }

        $this->finishEvent($eventId, 'accepted', 'No terminal status change.');
        return ['result' => 'accepted', 'message' => 'No terminal status change.'];
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public function confirmPaid(
        array $attempt,
        int $eventId,
        string $provider,
        string $providerTransactionId,
        float $amount,
        string $currency
    ): array {
        $conn = $this->paymentDb->getConnection();
        $incrementId = (string) $attempt['magento_increment_id'];
        $now = date('Y-m-d H:i:s');
        $order = $this->findMagentoOrder($incrementId);

        $conn->beginTransaction();
        try {
            $freshAttempt = $this->paymentDb->findAttemptById((int) $attempt['id']) ?: $attempt;
            $pvOrder = $this->paymentDb->findById((int) $freshAttempt['pv_order_id']);
            if (!$pvOrder) {
                throw new \RuntimeException('Local payment order not found.');
            }
            if (($pvOrder['payment_status'] ?? '') === 'paid' || ($freshAttempt['status'] ?? '') === 'paid') {
                $conn->commit();
                $this->finishEvent($eventId, 'duplicate');
                return ['result' => 'duplicate', 'message' => 'Already paid.'];
            }

            $this->paymentDb->transitionAttempt((int) $freshAttempt['id'], 'paid', [
                'provider_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
                'signature_verified' => 1,
                'paid_at' => $now,
            ]);
            $this->paymentDb->transitionOrder((int) $pvOrder['id'], 'paid', [
                'provider_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
                'signature_verified' => 1,
                'paid_at' => $now,
                'current_step' => 5,
            ]);
            $this->finishEvent($eventId, 'accepted');

            if ($order && $order->getId() && $order->getPayment()) {
                $payment = $order->getPayment();
                $payment->setAdditionalInformation('pvmodern_payment_status', OrderPaymentStatus::PAID);
                $payment->setAdditionalInformation('pvmodern_payment_gateway', $provider);
                $payment->setAdditionalInformation('pvmodern_payment_transaction_id', $providerTransactionId);
                $payment->setAdditionalInformation('pvmodern_paid_at', gmdate('c', strtotime($now)));
                $order->setTotalPaid((float) $order->getGrandTotal());
                $order->setBaseTotalPaid((float) $order->getBaseGrandTotal());
                if (!$order->isCanceled()) {
                    $order->setState(Order::STATE_PROCESSING);
                    $order->setStatus(Order::STATE_PROCESSING);
                }
                $order->addCommentToStatusHistory(
                    sprintf(
                        'Verified %s payment received. Amount: %.2f %s. Transaction: %s',
                        strtoupper($provider),
                        $amount,
                        strtoupper($currency ?: 'VND'),
                        $providerTransactionId ?: 'n/a'
                    )
                );
                $this->orderRepository->save($order);
            }

            $conn->commit();
        } catch (\Throwable $exception) {
            $conn->rollBack();
            $this->finishEvent($eventId, 'error', $exception->getMessage());
            throw $exception;
        }

        if ($order && $order->getId()) {
            $this->triggerFulfillmentOnce($order);
        }

        return ['result' => 'accepted', 'message' => 'Payment confirmed.'];
    }

    /**
     * @param array<string, mixed> $attempt
     */
    public function markFailed(array $attempt, string $providerTransactionId, string $reason): void
    {
        $pvOrder = $this->paymentDb->findById((int) $attempt['pv_order_id']);
        if ($pvOrder && ($pvOrder['payment_status'] ?? '') === 'paid') {
            return;
        }

        $this->paymentDb->transitionAttempt((int) $attempt['id'], 'failed', [
            'provider_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
        ]);
        if ($pvOrder) {
            $this->paymentDb->transitionOrder((int) $pvOrder['id'], 'failed', [
                'provider_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
            ]);
        }

        $order = $this->findMagentoOrder((string) $attempt['magento_increment_id']);
        if ($order && $order->getPayment()) {
            $order->getPayment()->setAdditionalInformation('pvmodern_payment_status', OrderPaymentStatus::FAILED);
            $order->addCommentToStatusHistory('Verified payment failure received. ' . $reason);
            $this->orderRepository->save($order);
        }
    }

    /**
     * @param array<string, mixed> $attempt
     */
    public function moveToManualReview(array $attempt, string $reason): void
    {
        $this->paymentDb->transitionAttempt((int) $attempt['id'], 'manual_review');
        $this->paymentDb->transitionOrder((int) $attempt['pv_order_id'], 'manual_review', ['admin_note' => $reason]);

        $order = $this->findMagentoOrder((string) $attempt['magento_increment_id']);
        if ($order && $order->getPayment()) {
            $order->getPayment()->setAdditionalInformation('pvmodern_payment_status', 'manual_review');
            $order->addCommentToStatusHistory('Payment moved to manual review. ' . $reason);
            $this->orderRepository->save($order);
        }
    }

    /**
     * @param array<string, mixed> $attempt
     */
    public function expireAttempt(array $attempt): void
    {
        $pvOrder = $this->paymentDb->findById((int) $attempt['pv_order_id']);
        if (!$pvOrder || ($pvOrder['payment_status'] ?? '') === 'paid') {
            return;
        }

        $this->paymentDb->transitionAttempt((int) $attempt['id'], 'expired');
        $this->paymentDb->transitionOrder((int) $attempt['pv_order_id'], 'expired', ['expired_at' => date('Y-m-d H:i:s')]);

        $order = $this->findMagentoOrder((string) $attempt['magento_increment_id']);
        if ($order && $order->getId() && !$order->isCanceled()) {
            if ($order->canCancel()) {
                $order->cancel();
            }
            if ($order->getPayment()) {
                $order->getPayment()->setAdditionalInformation('pvmodern_payment_status', 'expired');
            }
            $order->addCommentToStatusHistory('Payment attempt expired after 30 minutes. Inventory reservation released where Magento allows cancellation.');
            $this->orderRepository->save($order);
        }
    }

    public function triggerFulfillmentOnce(Order $order): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }
        if ((string) $payment->getAdditionalInformation('pvmodern_fulfillment_triggered') === '1') {
            return;
        }

        $provider = (string) $payment->getAdditionalInformation('pvmodern_shipping_provider');
        $contextRaw = (string) $payment->getAdditionalInformation('pvmodern_shipping_context');
        $context = [];
        if ($contextRaw !== '') {
            try {
                $decoded = $this->json->unserialize($contextRaw);
                $context = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $context = [];
            }
        }

        $created = $this->paymentDb->createFulfillmentJobIfMissing([
            'magento_increment_id' => (string) $order->getIncrementId(),
            'order_entity_id' => (int) $order->getEntityId(),
            'status' => 'pending',
            'provider' => $provider ?: null,
            'raw_context' => $this->serializeSafe($context),
        ]);
        if (!$created) {
            return;
        }

        try {
            if ($provider !== '' && $provider !== 'pickup') {
                $shipment = $this->shippingManager->createShipment($provider, $context);
                if (!empty($shipment['tracking_number'])) {
                    $order->addCommentToStatusHistory(
                        sprintf(
                            'Fulfillment created after verified payment with %s. Tracking: %s',
                            strtoupper($provider),
                            (string) $shipment['tracking_number']
                        )
                    );
                } else {
                    $order->addCommentToStatusHistory('Fulfillment processed after verified payment.');
                }
                $this->paymentDb->updateFulfillmentJob((string) $order->getIncrementId(), [
                    'status' => 'processed',
                    'result_payload' => $this->serializeSafe($shipment),
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $order->addCommentToStatusHistory('Store pickup fulfillment released after verified payment.');
                $this->paymentDb->updateFulfillmentJob((string) $order->getIncrementId(), [
                    'status' => 'processed',
                    'result_payload' => $this->serializeSafe(['provider' => 'pickup']),
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $payment->setAdditionalInformation('pvmodern_fulfillment_triggered', '1');
            $this->orderRepository->save($order);
        } catch (\Throwable $exception) {
            $this->paymentDb->updateFulfillmentJob((string) $order->getIncrementId(), [
                'status' => 'error',
                'result_payload' => $this->serializeSafe(['error' => $exception->getMessage()]),
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->logger->error('[PVModern][Payment] fulfillment failed after payment confirmation', [
                'order' => $order->getIncrementId(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function findMagentoOrder(string $incrementId): ?Order
    {
        if ($incrementId === '') {
            return null;
        }
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', ['in' => [$incrementId, ltrim($incrementId, '0') ?: '0']]);
        $collection->setPageSize(1);
        $order = $collection->getFirstItem();
        return $order && $order->getId() ? $order : null;
    }

    public function finishEvent(int $eventId, string $result, string $message = ''): void
    {
        if ($eventId <= 0) {
            return;
        }
        $this->paymentDb->updatePaymentEvent($eventId, [
            'processing_result' => $result,
            'error_message' => $message !== '' ? $message : null,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function normalizeDate(string $date, int $fallbackTimestamp): string
    {
        $timestamp = $date !== '' ? strtotime($date) : false;
        return date('Y-m-d H:i:s', $timestamp ?: $fallbackTimestamp);
    }

    private function serializeSafe(mixed $payload): string
    {
        try {
            return $this->json->serialize($payload);
        } catch (\Throwable) {
            return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '';
        }
    }
}
