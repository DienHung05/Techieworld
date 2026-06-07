<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Router;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\UrlInterface;

class HomepageNewsRouter implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (method_exists($request, 'getMethod') && !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        if (trim((string) $request->getPathInfo(), '/') !== '') {
            return null;
        }

        if (method_exists($request, 'getServerValue') && (string) $request->getServerValue('QUERY_STRING', '') !== '') {
            return null;
        }

        if (method_exists($request, 'getRequestUri') && str_contains((string) $request->getRequestUri(), '?')) {
            return null;
        }

        $request->setAlias(UrlInterface::REWRITE_REQUEST_PATH_ALIAS, '');
        $request->setPathInfo('/news');

        return $this->actionFactory->create(Forward::class);
    }
}
