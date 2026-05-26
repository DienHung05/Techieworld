<?php
declare(strict_types=1);
namespace YourVendor\PVModern\Controller\Admin;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use YourVendor\PVModern\Helper\PaymentDb;

class Orders implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PaymentDb $paymentDb,
        private readonly Login $loginHelper
    ) {}

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        if (!$this->loginHelper->isAuthenticated()) {
            return $result->setHttpResponseCode(401)->setData(['error' => 'Unauthorized']);
        }

        $tab  = (string)($this->request->getParam('tab') ?? '');
        $date = (string)($this->request->getParam('date') ?? '');

        if ($tab === 'casso_log') {
            $logs = $this->paymentDb->listVerifications(0, $date ?: date('Y-m-d'));
            return $result->setData(['verifications' => $logs]);
        }

        $filters = [
            'status' => (string)($this->request->getParam('status') ?? ''),
            'method' => (string)($this->request->getParam('method') ?? ''),
            'date'   => $date,
            'search' => (string)($this->request->getParam('search') ?? ''),
        ];
        $orders = $this->paymentDb->listOrders($filters);

        // Stats
        $cassoToday = $this->countCassoToday();
        $stats = [
            'pending_review' => $this->paymentDb->countOrders('pending_review'),
            'manual_review'  => $this->paymentDb->countOrders('manual_review'),
            'paid'           => $this->paymentDb->countOrders('paid'),
            'total'          => $this->paymentDb->countOrders(),
            'casso_today'    => $cassoToday,
        ];

        return $result->setData(['orders' => $orders, 'stats' => $stats]);
    }

    private function countCassoToday(): int
    {
        $logs = $this->paymentDb->listVerifications(0, date('Y-m-d'));
        return count(array_filter($logs, fn($l) => $l['source'] === 'casso'));
    }
}
