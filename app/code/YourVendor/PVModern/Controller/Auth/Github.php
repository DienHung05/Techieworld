<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Auth;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Github implements HttpGetActionInterface
{
    private const CONFIG_CLIENT_ID = 'pvmodern/github_oauth/client_id';

    public function __construct(
        private readonly RequestInterface        $request,
        private readonly RedirectFactory         $redirectFactory,
        private readonly SessionManagerInterface $session,
        private readonly ScopeConfigInterface    $scopeConfig
    ) {
    }

    public function execute()
    {
        $clientId = (string) $this->scopeConfig->getValue(self::CONFIG_CLIENT_ID, ScopeInterface::SCOPE_STORE);

        if (empty($clientId)) {
            $clientId = (string) ($_ENV['GITHUB_OAUTH_CLIENT_ID'] ?? getenv('GITHUB_OAUTH_CLIENT_ID') ?? '');
        }

        $state = bin2hex(random_bytes(16));
        $this->session->setData('github_oauth_state', $state);

        $params = http_build_query([
            'client_id'    => $clientId,
            'redirect_uri' => $this->request->getDistroBaseUrl() . 'pvmodern/auth/githubCallback',
            'scope'        => 'user:email',
            'state'        => $state,
        ]);

        $redirect = $this->redirectFactory->create();
        $redirect->setUrl('https://github.com/login/oauth/authorize?' . $params);
        return $redirect;
    }
}
