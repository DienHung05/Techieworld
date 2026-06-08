<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Shipping;

use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Api\ShippingProviderInterface;

class ShippingManager
{
    
    private array $providers;

    private LoggerInterface $logger;

    public function __construct(
        array $providers = [],
        ?LoggerInterface $logger = null
    ) {
        $objectManager = ObjectManager::getInstance();

        if (empty($providers)) {
            $providers = [
                'ghn' => $objectManager->get(\YourVendor\PVModern\Model\Shipping\Provider\GhnProvider::class),
                'ghtk' => $objectManager->get(\YourVendor\PVModern\Model\Shipping\Provider\GhtkProvider::class),
                'spx' => $objectManager->get(\YourVendor\PVModern\Model\Shipping\Provider\SpxProvider::class),
            ];
        }

        $this->providers = $providers;
        $this->logger = $logger ?: $objectManager->get(LoggerInterface::class);
    }

    
    public function getQuotes(array $context): array
    {
        $quotes = [];

        foreach ($this->providers as $provider) {
            try {
                if (!$provider->isAvailable($context)) {
                    continue;
                }

                $quotes[] = $provider->quote($context);
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    sprintf('[PVModern][Shipping:%s] quote failure', $provider->getCode()),
                    ['message' => $exception->getMessage()]
                );
            }
        }

        usort($quotes, static fn(array $left, array $right): int => ($left['amount'] <=> $right['amount']));

        return $quotes;
    }

    public function getProvider(string $code): ?ShippingProviderInterface
    {
        return $this->providers[$code] ?? null;
    }

    
    public function createShipment(string $code, array $context): array
    {
        $provider = $this->getProvider($code);
        if (!$provider) {
            return [
                'provider' => $code,
                'status' => 'skipped',
                'message' => 'Shipment provider is not configured.',
            ];
        }

        return $provider->createShipment($context);
    }

    
    public function track(string $code, string $trackingNumber): array
    {
        $provider = $this->getProvider($code);
        if (!$provider) {
            return [
                'provider' => $code,
                'status' => 'unknown',
                'tracking_number' => $trackingNumber,
            ];
        }

        return $provider->track($trackingNumber);
    }

    
    public function cancel(string $code, string $shipmentId): array
    {
        $provider = $this->getProvider($code);
        if (!$provider) {
            return [
                'provider' => $code,
                'cancelled' => false,
                'shipment_id' => $shipmentId,
            ];
        }

        return $provider->cancel($shipmentId);
    }
}
