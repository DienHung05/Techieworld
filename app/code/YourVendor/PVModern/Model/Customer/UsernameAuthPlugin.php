<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Customer;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

class UsernameAuthPlugin
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * If the login identifier is not an email, look it up as pv_username and
     * rewrite it to the matching customer's email so Magento's standard
     * AccountManagement::authenticate() works unchanged.
     *
     * @return array{0:string,1:string}
     */
    public function beforeAuthenticate(
        AccountManagementInterface $subject,
        $email,
        $password
    ): array {
        $identifier = (string) $email;
        if ($identifier === '' || $this->looksLikeEmail($identifier)) {
            return [$email, $password];
        }

        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('pv_username', $identifier, 'eq')
            ->addFilter('website_id', $websiteId, 'eq')
            ->setPageSize(1)
            ->create();

        $items = $this->customerRepository->getList($criteria)->getItems();
        if (!empty($items)) {
            $customer = reset($items);
            return [(string) $customer->getEmail(), $password];
        }

        return [$email, $password];
    }

    private function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
