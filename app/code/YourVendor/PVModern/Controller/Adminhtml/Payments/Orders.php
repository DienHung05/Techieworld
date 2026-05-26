<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Adminhtml\Payments;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use YourVendor\PVModern\Helper\PaymentDb;

class Orders extends Action
{
    public const ADMIN_RESOURCE = 'YourVendor_PVModern::payments';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PaymentDb $paymentDb
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache', true);

        $tab    = (string)$this->getRequest()->getParam('tab', 'pending_review');
        $method = (string)$this->getRequest()->getParam('method', '');
        $date   = (string)$this->getRequest()->getParam('date', '');
        $search = (string)$this->getRequest()->getParam('search', '');

        if ($tab === 'casso_log') {
            $logs = $this->paymentDb->listVerifications(0, $date ?: date('Y-m-d'));
            return $result->setData(['verifications' => $logs]);
        }

        $filters = [
            'status' => $tab === 'all' ? '' : $tab,
            'method' => $method,
            'date'   => $date,
            'search' => $search,
        ];

        $orders = $this->paymentDb->listOrders($filters, 200);

        $stats = [
            'pending_review' => $this->paymentDb->countOrders('pending_review'),
            'manual_review'  => $this->paymentDb->countOrders('manual_review'),
            'paid'           => $this->paymentDb->countOrders('paid'),
            'total'          => $this->paymentDb->countOrders(),
        ];

        return $result->setData(['orders' => $orders, 'stats' => $stats]);
    }
}
