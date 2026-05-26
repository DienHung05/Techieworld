<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\News;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Result\PageFactory;

class Index extends \YourVendor\PVModern\Controller\Api\News
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
        $page->getConfig()->getTitle()->set(__('Business News'));
        return $page;
    }
}
