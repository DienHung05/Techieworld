<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Api;

interface PaymentProviderInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    public function getMethodCode(): string;

    public function getInitialStatus(): string;

    public function isAvailable(array $context = []): bool;

    
    public function describeCheckoutMethod(array $context = []): array;

    
    public function initialize(array $context): array;
}
