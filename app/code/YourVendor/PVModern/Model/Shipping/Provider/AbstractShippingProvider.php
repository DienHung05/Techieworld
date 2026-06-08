<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Shipping\Provider;

use Psr\Log\LoggerInterface;
use YourVendor\PVModern\Api\ShippingProviderInterface;
use YourVendor\PVModern\Model\IntegrationConfig;

abstract class AbstractShippingProvider implements ShippingProviderInterface
{
    public function __construct(
        protected readonly IntegrationConfig $integrationConfig,
        protected readonly LoggerInterface $logger
    ) {
    }

    abstract protected function getBaseAmount(): float;

    abstract protected function getEtaRange(): array;

    abstract protected function getProviderNote(): string;

    
    protected function getProviderBrand(): array
    {
        return [
            'color'      => '#6366f1',
            'short_name' => strtoupper($this->getCode()),
            'logo_key'   => $this->getCode(),
        ];
    }

    protected function getProviderToken(): ?string
    {
        return $this->integrationConfig->getShippingToken($this->getCode());
    }

    protected function isMockMode(): bool
    {
        return $this->integrationConfig->isMockModeEnabled('shipping') || !$this->getProviderToken();
    }

    public function isAvailable(array $context = []): bool
    {
        return true;
    }

    public function quote(array $context): array
    {
        $itemCount = max(1, (int) ($context['item_count'] ?? 1));
        $weight = max(0.5, (float) ($context['package_weight'] ?? ($itemCount * 0.65)));
        $city = strtolower((string) ($context['city'] ?? ''));
        $distanceMultiplier = str_contains($city, 'ha noi') || str_contains($city, 'ho chi minh') ? 1.0 : 1.18;
        $amount = round(($this->getBaseAmount() + ($itemCount * 0.85) + ($weight * 0.55)) * $distanceMultiplier, 2);
        [$etaMin, $etaMax] = $this->getEtaRange();

        return [
            'provider'    => $this->getCode(),
            'label'       => $this->getLabel(),
            'method_code' => 'pvmodernshipping_' . $this->getCode(),
            'amount'      => $amount,
            'currency'    => 'USD',
            'eta_label'   => sprintf('%d–%d ngày', $etaMin, $etaMax),
            'eta_min'     => $etaMin,
            'eta_max'     => $etaMax,
            'description' => $this->getProviderNote(),
            'brand'       => $this->getProviderBrand(),
            'mock'        => $this->isMockMode(),
        ];
    }

    public function createShipment(array $context): array
    {
        $prefix = strtoupper($this->getCode());
        $timestamp = gmdate('YmdHis');

        return [
            'provider' => $this->getCode(),
            'tracking_number' => $prefix . '-' . $timestamp . '-' . random_int(1000, 9999),
            'shipment_id' => $prefix . '-SHIP-' . random_int(100000, 999999),
            'status' => 'created',
            'mock' => $this->isMockMode(),
            'message' => $this->isMockMode()
                ? 'Mock shipment created. Replace adapter mapping when live API contracts are available.'
                : 'Shipment created successfully.',
        ];
    }

    public function track(string $trackingNumber): array
    {
        return [
            'provider' => $this->getCode(),
            'tracking_number' => $trackingNumber,
            'status' => 'label_created',
            'timeline' => [
                ['label' => 'Order received', 'time' => gmdate('c', strtotime('-2 hours'))],
                ['label' => 'Carrier label generated', 'time' => gmdate('c')],
            ],
            'mock' => $this->isMockMode(),
        ];
    }

    public function cancel(string $shipmentId): array
    {
        return [
            'provider' => $this->getCode(),
            'shipment_id' => $shipmentId,
            'cancelled' => true,
            'mock' => $this->isMockMode(),
        ];
    }
}
