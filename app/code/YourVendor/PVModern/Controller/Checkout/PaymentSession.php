<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;

class PaymentSession implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(private readonly ForwardFactory $forwardFactory)
    {
    }

    public function execute()
    {
        return $this->forwardFactory->create()
            ->setController('payments')
            ->forward('create');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
