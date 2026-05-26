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

class Approve implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $json,
        private readonly PaymentDb $paymentDb,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly Login $loginHelper
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException { return null; }
    public function validateForCsrf(RequestInterface $request): ?bool { return true; }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        if (!$this->loginHelper->isAuthenticated()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false, 'message' => 'Unauthorized']);
        }

        try {
            $body = $this->json->unserialize($this->request->getContent());
        } catch (\Throwable $e) {
            $body = [];
        }
        $id   = (int)($body['id'] ?? $this->request->getParam('id') ?? 0);
        $note = (string)($body['note'] ?? '');

        if ($id <= 0) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'Invalid ID']);
        }
        $pvOrder = $this->paymentDb->findById($id);
        if (!$pvOrder) {
            return $result->setHttpResponseCode(404)->setData(['success' => false, 'message' => 'Not found']);
        }
        if ($pvOrder['payment_status'] === 'paid') {
            return $result->setData(['success' => true, 'message' => 'Already paid']);
        }

        $attempt = $this->paymentDb->findLatestAttemptForIncrement((string)$pvOrder['magento_increment_id']);
        if ($attempt) {
            $eventId = $this->paymentAttemptService->recordEvent(
                $attempt,
                (string)($attempt['provider'] ?? 'manual'),
                'manual_admin',
                null,
                null,
                true,
                (float)$pvOrder['total_amount'],
                (string)($pvOrder['currency'] ?? 'VND'),
                $this->json->serialize(['note' => $note])
            );
            $this->paymentAttemptService->confirmPaid(
                $attempt,
                $eventId,
                (string)($attempt['provider'] ?? 'manual'),
                '',
                (float)$pvOrder['total_amount'],
                (string)($pvOrder['currency'] ?? 'VND')
            );
            return $result->setData(['success' => true, 'message' => 'Order approved']);
        }

        $this->paymentDb->updateOrder($id, [
            'payment_status' => 'paid',
            'current_step' => 5,
            'paid_at' => date('Y-m-d H:i:s'),
            'admin_note' => $note ?: 'Admin approved manually',
        ]);

        $this->paymentDb->logVerification([
            'pv_order_id' => $id,
            'source' => 'manual_admin',
            'amount' => (float)$pvOrder['total_amount'],
            'transaction_id' => null,
            'raw_payload' => null,
            'admin_user' => 'admin',
            'note' => 'Admin approved: ' . $note,
        ]);

        return $result->setData(['success' => true, 'message' => 'Order approved']);
    }
}
