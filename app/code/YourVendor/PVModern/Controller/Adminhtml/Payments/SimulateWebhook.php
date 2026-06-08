<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Adminhtml\Payments;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use YourVendor\PVModern\Helper\PaymentDb;
use YourVendor\PVModern\Model\IntegrationConfig;
use YourVendor\PVModern\Model\Payment\CassoTransactionProcessor;


class SimulateWebhook extends Action
{
    public const ADMIN_RESOURCE = 'YourVendor_PVModern::payments';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $json,
        private readonly PaymentDb $paymentDb,
        private readonly IntegrationConfig $integrationConfig,
        private readonly CassoTransactionProcessor $transactionProcessor
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->integrationConfig->isMockModeEnabled('payment')) {
            return $result->setHttpResponseCode(403)->setData([
                'success' => false,
                'message' => 'Simulate webhook is only available in mock/sandbox mode.',
            ]);
        }

        $pvOrderId = (int) ($this->getRequest()->getParam('pv_order_id') ?? 0);
        $incrementId = (string) ($this->getRequest()->getParam('increment_id') ?? '');

        $pvOrder = null;
        if ($pvOrderId > 0) {
            $pvOrder = $this->paymentDb->findById($pvOrderId);
        } elseif ($incrementId !== '') {
            $pvOrder = $this->paymentDb->findByIncrementId($incrementId);
        }

        if (!$pvOrder) {
            return $result->setHttpResponseCode(404)->setData([
                'success' => false,
                'message' => 'Order not found.',
            ]);
        }

        if ($pvOrder['payment_status'] === 'paid') {
            return $result->setData([
                'success' => true,
                'message' => 'Order is already paid.',
                'payment_status' => 'paid',
            ]);
        }

        $transferCode = (string) ($pvOrder['transfer_code'] ?? '');
        $amount = (float) ($pvOrder['total_amount'] ?? 0);

        if ($transferCode === '') {
            return $result->setHttpResponseCode(422)->setData([
                'success' => false,
                'message' => 'Order has no transfer_code — cannot simulate.',
            ]);
        }

        $fakeTransactionId = 'SIM' . time() . random_int(1000, 9999);
        $transaction = [
            'tid'         => $fakeTransactionId,
            'amount'      => $amount,
            'description' => 'SIMULATE ' . $transferCode . ' thanh toan don hang',
            'kind'        => 1,
        ];

        $rawPayload = $this->json->serialize([
            'simulated' => true,
            'pv_order_id' => $pvOrder['id'],
            'increment_id' => $pvOrder['magento_increment_id'],
        ]);

        $processorResult = $this->transactionProcessor->process($transaction, $rawPayload, true);

        $order = $this->paymentDb->findById((int) $pvOrder['id']);

        return $result->setData([
            'success' => true,
            'message' => 'Simulated Casso webhook fired.',
            'processor_result' => $processorResult,
            'payment_status' => $order['payment_status'] ?? 'unknown',
            'transfer_code' => $transferCode,
            'simulated_amount' => $amount,
        ]);
    }
}
