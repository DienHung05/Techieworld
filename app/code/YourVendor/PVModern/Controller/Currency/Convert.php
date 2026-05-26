<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Currency;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Convert extends \YourVendor\PVModern\Controller\Api\Currency
{
    public function __construct(
        private readonly RequestInterface $aliasRequest,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($aliasRequest, $resultJsonFactory);
    }

    public function execute()
    {
        $this->aliasRequest->setParam('mode', 'convert');
        return parent::execute();
    }
}
