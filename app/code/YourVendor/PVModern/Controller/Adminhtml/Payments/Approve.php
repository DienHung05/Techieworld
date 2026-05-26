<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Adminhtml\Payments;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\Payment\PaymentAttemptService;

class Approve extends Action
{
    public const ADMIN_RESOURCE = 'YourVendor_PVModern::payments';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $json,
        private readonly PaymentDb $paymentDb,
        private readonly PaymentAttemptService $paymentAttemptService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $body = $this->json->unserialize((string)$this->getRequest()->getContent());
        } catch (\Throwable) {
            $body = [];
        }

        $id   = (int)($body['id'] ?? $this->getRequest()->getParam('id') ?? 0);
        $note = (string)($body['note'] ?? '');

        if ($id <= 0) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'Invalid order ID']);
        }

        $pvOrder = $this->paymentDb->findById($id);
        if (!$pvOrder) {
            return $result->setHttpResponseCode(404)->setData(['success' => false, 'message' => 'Order not found']);
        }
        if ($pvOrder['payment_status'] === 'paid') {
            return $result->setData(['success' => true, 'message' => 'Already paid']);
        }

        $adminUser = $this->_auth->getUser()->getUserName();
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
                $this->json->serialize(['admin_user' => $adminUser, 'note' => $note])
            );
            $this->paymentAttemptService->confirmPaid(
                $attempt,
                $eventId,
                (string)($attempt['provider'] ?? 'manual'),
                '',
                (float)$pvOrder['total_amount'],
                (string)($pvOrder['currency'] ?? 'VND')
            );
            return $result->setData(['success' => true, 'message' => 'Đã xác nhận thanh toán thành công']);
        }

        $this->paymentDb->updateOrder($id, [
            'payment_status' => 'paid',
            'current_step'   => 5,
            'paid_at'        => date('Y-m-d H:i:s'),
            'admin_note'     => $note ?: 'Approved by ' . $adminUser,
        ]);

        $this->paymentDb->logVerification([
            'pv_order_id'    => $id,
            'source'         => 'manual_admin',
            'amount'         => (float)$pvOrder['total_amount'],
            'transaction_id' => null,
            'raw_payload'    => null,
            'admin_user'     => $adminUser,
            'note'           => 'Approved. ' . $note,
        ]);

        return $result->setData(['success' => true, 'message' => 'Đã xác nhận thanh toán thành công']);
    }
}
