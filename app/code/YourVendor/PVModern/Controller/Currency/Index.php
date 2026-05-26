<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Currency;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Result\PageFactory;

class Index extends \YourVendor\PVModern\Controller\Api\Currency
{
    public function __construct(
        private readonly RequestInterface $routeRequest,
        JsonFactory $resultJsonFactory,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($routeRequest, $resultJsonFactory);
    }

    public function execute()
    {
        if ((string) $this->routeRequest->getRouteName() === 'api') {
            return parent::execute();
        }

        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set(__('Currency Rate'));
        return $page;
    }
}
