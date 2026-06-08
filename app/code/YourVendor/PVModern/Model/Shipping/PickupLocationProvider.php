<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Shipping;

use YourVendor\PVModern\Model\IntegrationConfig;

class PickupLocationProvider
{
    public function __construct(
        private readonly IntegrationConfig $integrationConfig
    ) {
    }

    
    public function getLocations(): array
    {
        $json = $this->integrationConfig->getString('PVMODERN_PICKUP_STORES_JSON');
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded) && !empty($decoded)) {
                return array_values(array_filter($decoded, 'is_array'));
            }
        }

        return [
            [
                'code' => 'hcm-flagship',
                'name' => 'Techieworld Flagship Store',
                'street' => '88 Nguyen Hue Boulevard',
                'district' => 'District 1',
                'city' => 'Ho Chi Minh City',
                'region' => 'Ho Chi Minh',
                'postcode' => '700000',
                'country_id' => 'VN',
                'phone' => '+84 28 7300 6688',
                'hours' => '09:00 - 21:00',
            ],
            [
                'code' => 'hn-showroom',
                'name' => 'Techieworld Hanoi Showroom',
                'street' => '26 Ba Trieu Street',
                'district' => 'Hoan Kiem',
                'city' => 'Ha Noi',
                'region' => 'Ha Noi',
                'postcode' => '100000',
                'country_id' => 'VN',
                'phone' => '+84 24 3210 5566',
                'hours' => '09:00 - 20:30',
            ],
            [
                'code' => 'dn-service-hub',
                'name' => 'Techieworld Da Nang Service Hub',
                'street' => '155 Bach Dang',
                'district' => 'Hai Chau',
                'city' => 'Da Nang',
                'region' => 'Da Nang',
                'postcode' => '550000',
                'country_id' => 'VN',
                'phone' => '+84 236 388 7788',
                'hours' => '09:00 - 20:00',
            ],
        ];
    }

    
    public function getLocation(string $code): ?array
    {
        foreach ($this->getLocations() as $location) {
            if (($location['code'] ?? '') === $code) {
                return $location;
            }
        }

        return null;
    }
}
