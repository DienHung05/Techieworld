<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Payment;

use Magento\Framework\App\ObjectManager;
use YourVendor\PVModern\Api\PaymentProviderInterface;

class PaymentManager
{
    
    private array $providers;

    public function __construct(
        array $providers = []
    ) {
        $objectManager = ObjectManager::getInstance();

        if (empty($providers)) {
            $providers = [
                'cod' => $objectManager->get(\YourVendor\PVModern\Model\Payment\Provider\CodPaymentProvider::class),
                'bank_transfer' => $objectManager->get(\YourVendor\PVModern\Model\Payment\Provider\BankTransferPaymentProvider::class),
                'online_gateway' => $objectManager->get(\YourVendor\PVModern\Model\Payment\Provider\OnlineGatewayPaymentProvider::class),
            ];
        }

        $this->providers = $providers;
    }

    
    public function getFrontendMethods(array $context = []): array
    {
        $methods = [];

        foreach ($this->providers as $provider) {
            if ($provider->isAvailable($context)) {
                $methods[] = $provider->describeCheckoutMethod($context);
            }
        }

        return $methods;
    }

    public function getProvider(string $code): ?PaymentProviderInterface
    {
        return $this->providers[$code] ?? null;
    }
}
