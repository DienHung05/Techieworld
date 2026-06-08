<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Api;

interface ShippingProviderInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    public function isAvailable(array $context = []): bool;

    
    public function quote(array $context): array;

    
    public function createShipment(array $context): array;

    
    public function track(string $trackingNumber): array;

    
    public function cancel(string $shipmentId): array;
}
