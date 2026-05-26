<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Admin;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class ResolvePaymentReview implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $json,
        private readonly PaymentDb $paymentDb,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly Login $loginHelper
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        if (!$this->loginHelper->isAuthenticated()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false, 'message' => 'Unauthorized']);
        }

        try {
            $body = $this->json->unserialize((string) $this->request->getContent());
        } catch (\Throwable) {
            $body = [];
        }

        $transactionId = (string) ($body['transactionId'] ?? $body['transaction_id'] ?? $this->request->getParam('transactionId') ?? '');
        $action = (string) ($body['action'] ?? $this->request->getParam('action') ?? 'resolve');
        $pvOrderId = (int) ($body['pv_order_id'] ?? $body['matched_order_id'] ?? $this->request->getParam('pv_order_id') ?? 0);
        $review = $this->paymentDb->findReviewByTransactionId($transactionId);
        if (!$review) {
            return $result->setHttpResponseCode(404)->setData(['success' => false, 'message' => 'Review not found']);
        }

        if ($action === 'ignore') {
            $this->paymentDb->updateReview((int) $review['id'], [
                'status' => 'ignored',
                'resolved_by' => 'admin',
                'resolved_at' => date('Y-m-d H:i:s'),
            ]);
            return $result->setData(['success' => true, 'message' => 'Review ignored']);
        }

        $pvOrder = $pvOrderId > 0 ? $this->paymentDb->findById($pvOrderId) : null;
        if (!$pvOrder) {
            return $result->setHttpResponseCode(422)->setData(['success' => false, 'message' => 'Matched order is required']);
        }

        $amount = (float) $review['amount'];
        $expected = (float) $pvOrder['total_amount'];
        if (abs($amount - $expected) > 0.01) {
            return $result->setHttpResponseCode(422)->setData(['success' => false, 'message' => 'Amount does not match order total']);
        }

        $attempt = $this->paymentDb->findLatestAttemptForIncrement((string) $pvOrder['magento_increment_id']);
        if (!$attempt) {
            return $result->setHttpResponseCode(422)->setData(['success' => false, 'message' => 'Payment attempt not found for matched order']);
        }

        $eventId = $this->paymentAttemptService->recordEvent(
            $attempt,
            'casso',
            'manual_review_resolve',
            $transactionId !== '' ? $transactionId : null,
            $transactionId !== '' ? $transactionId : null,
            true,
            $amount,
            'VND',
            (string) ($review['raw_payload'] ?? '')
        );
        $this->paymentAttemptService->confirmPaid($attempt, $eventId, 'casso', $transactionId, $amount, 'VND');
        $this->paymentDb->updateReview((int) $review['id'], [
            'matched_order_id' => (int) $pvOrder['id'],
            'status' => 'resolved',
            'resolved_by' => 'admin',
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);

        return $result->setData(['success' => true, 'message' => 'Review resolved and order marked paid']);
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
