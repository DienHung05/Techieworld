<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Auth;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class GithubCallback implements HttpGetActionInterface
{
    private const CONFIG_CLIENT_ID     = 'pvmodern/github_oauth/client_id';
    private const CONFIG_CLIENT_SECRET = 'pvmodern/github_oauth/client_secret';

    public function __construct(
        private readonly RequestInterface            $request,
        private readonly RedirectFactory             $redirectFactory,
        private readonly SessionManagerInterface     $session,
        private readonly CustomerSession             $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory    $customerFactory,
        private readonly AccountManagementInterface  $accountManagement,
        private readonly StoreManagerInterface       $storeManager,
        private readonly ScopeConfigInterface        $scopeConfig,
        private readonly MessageManager              $messageManager
    ) {
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        $code  = (string) $this->request->getParam('code', '');
        $state = (string) $this->request->getParam('state', '');

        $savedState = (string) $this->session->getData('github_oauth_state');
        $this->session->unsData('github_oauth_state');

        if (empty($code) || $state !== $savedState) {
            $this->messageManager->addErrorMessage('GitHub login failed: invalid state. Please try again.');
            $redirect->setPath('customer/account/login');
            return $redirect;
        }

        $clientId     = $this->getConfig(self::CONFIG_CLIENT_ID,     'GITHUB_OAUTH_CLIENT_ID');
        $clientSecret = $this->getConfig(self::CONFIG_CLIENT_SECRET,  'GITHUB_OAUTH_CLIENT_SECRET');

        $tokenData = $this->githubPost('https://github.com/login/oauth/access_token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
        ]);

        if (empty($tokenData['access_token'])) {
            $this->messageManager->addErrorMessage('GitHub login failed: could not obtain access token.');
            $redirect->setPath('customer/account/login');
            return $redirect;
        }

        $token    = $tokenData['access_token'];
        $userData = $this->githubGet('https://api.github.com/user', $token);

        $email = (string) ($userData['email'] ?? '');
        if (empty($email)) {
            $emails = $this->githubGet('https://api.github.com/user/emails', $token);
            foreach ((array) $emails as $e) {
                if (!empty($e['primary']) && !empty($e['verified'])) {
                    $email = (string) $e['email'];
                    break;
                }
            }
            if (empty($email) && !empty($emails[0]['email'])) {
                $email = (string) $emails[0]['email'];
            }
        }

        if (empty($email)) {
            $this->messageManager->addErrorMessage('GitHub login failed: no email address found on your GitHub account.');
            $redirect->setPath('customer/account/login');
            return $redirect;
        }

        $storeId   = (int) $this->storeManager->getStore()->getId();
        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();

        try {
            $customer = $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            $nameParts = explode(' ', (string) ($userData['name'] ?? ''), 2);
            $firstName = $nameParts[0] ?: (string) ($userData['login'] ?? 'GitHub');
            $lastName  = $nameParts[1] ?? 'User';

            $customer = $this->customerFactory->create();
            $customer->setEmail($email)
                     ->setFirstname($firstName)
                     ->setLastname($lastName)
                     ->setStoreId($storeId)
                     ->setWebsiteId($websiteId);

            $customer = $this->accountManagement->createAccountWithPasswordHash(
                $customer,
                bin2hex(random_bytes(16))
            );
        }

        $this->customerSession->setCustomerDataAsLoggedIn($customer);
        $this->customerSession->regenerateId();

        $this->messageManager->addSuccessMessage('You have been signed in with GitHub.');
        $redirect->setPath('customer/account');
        return $redirect;
    }

    private function getConfig(string $path, string $envKey): string
    {
        $v = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        if (empty($v)) {
            $v = (string) ($_ENV[$envKey] ?? getenv($envKey) ?? '');
        }
        return $v;
    }

    private function githubPost(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: TechieWorld-Magento'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $body, true) ?: [];
    }

    private function githubGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: TechieWorld-Magento',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $body, true) ?: [];
    }
}
