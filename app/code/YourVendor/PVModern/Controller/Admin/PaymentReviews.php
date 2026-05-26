<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Admin;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use YourVendor\PVModern\Helper\PaymentDb;

class PaymentReviews implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PaymentDb $paymentDb,
        private readonly Login $loginHelper
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        if (!$this->loginHelper->isAuthenticated()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false, 'message' => 'Unauthorized']);
        }

        $status = (string) ($this->request->getParam('status') ?? '');
        return $result->setData([
            'success' => true,
            'reviews' => $this->paymentDb->listReviews($status),
        ]);
    }
}
