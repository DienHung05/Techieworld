<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\PaymentSessions;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\Payment\PaymentStatusPoller;

class RetryStatusCheck implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PaymentDb $paymentDb,
        private readonly PaymentStatusPoller $paymentStatusPoller
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $id = (int) ($this->request->getParam('paymentAttemptId') ?: $this->request->getParam('payment_attempt_id') ?: $this->request->getParam('id'));
        $attempt = $id > 0 ? $this->paymentDb->findAttemptById($id) : null;
        if (!$attempt) {
            return $result->setHttpResponseCode(404)->setData(['success' => false, 'message' => 'Payment attempt not found.']);
        }

        $pollResult = $this->paymentStatusPoller->pollAttempt($attempt);
        $attempt = $this->paymentDb->findAttemptById($id) ?: $attempt;

        return $result->setData([
            'success' => true,
            'pollResult' => $pollResult,
            'paymentAttemptId' => (int) $attempt['id'],
            'orderId' => (string) $attempt['magento_increment_id'],
            'method' => (string) $attempt['provider'],
            'status' => (string) $attempt['status'],
            'nextStepAllowed' => (string) $attempt['status'] === 'paid',
        ]);
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
