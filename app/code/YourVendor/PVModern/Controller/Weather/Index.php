<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Weather;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Result\PageFactory;
use YourVendor\PVModern\Model\IntegrationConfig;

class Index extends \YourVendor\PVModern\Controller\Api\Weather
{
    public function __construct(
        private readonly RequestInterface $routeRequest,
        JsonFactory $resultJsonFactory,
        private readonly PageFactory $resultPageFactory,
        IntegrationConfig $integrationConfig
    ) {
        parent::__construct($routeRequest, $resultJsonFactory, $integrationConfig);
    }

    public function execute()
    {
        if ((string) $this->routeRequest->getRouteName() === 'api') {
            return parent::execute();
        }

        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set(__('Weather'));
        return $page;
    }
}
