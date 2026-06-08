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
