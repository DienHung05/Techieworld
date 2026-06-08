<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model;

class CategoryNavigation
{
    
    public function getMenuConfig(): array
    {
        return [
            'desktop' => [
                'label' => 'Desktop',
                'category' => 'Desktop',
                'cols' => [
                    [
                        'title' => 'PC Components',
                        'links' => [
                            ['label' => 'GPU — Graphics Cards', 'category' => 'GPU'],
                            ['label' => 'CPU — Processors', 'category' => 'CPU'],
                            ['label' => 'RAM — Memory', 'category' => 'RAM'],
                            ['label' => 'SSD — Solid State', 'category' => 'SSD'],
                            ['label' => 'HDD — Hard Drives', 'category' => 'HDD'],
                            ['label' => 'Mainboard', 'category' => 'Mainboard'],
                            ['label' => 'PSU — Power Supply', 'category' => 'PSU'],
                        ],
                    ],
                    [
                        'title' => 'Cooling & Cases',
                        'links' => [
                            ['label' => 'CPU Heatsink & AIO', 'category' => 'Heatsink'],
                            ['label' => 'Case Fans', 'category' => 'Fan'],
                            ['label' => 'PC Cases', 'category' => 'Case'],
                            ['label' => 'PC Builder Tool', 'url' => 'pc-builder'],
                            ['label' => 'Warranty Lookup', 'url' => 'warranty'],
                            ['label' => 'Deals', 'url' => 'deals'],
                        ],
                    ],
                ],
            ],
            'laptop' => [
                'label' => 'Laptop',
                'category' => 'Laptop',
                'cols' => [
                    [
                        'title' => 'By Use Case',
                        'links' => [
                            ['label' => 'Gaming Laptops', 'category' => 'Gaming Laptop'],
                            ['label' => 'Creator Laptops', 'category' => 'Creator Laptop'],
                            ['label' => 'Business Laptops', 'category' => 'Business Laptop'],
                            ['label' => 'Ultrabooks', 'category' => 'Ultrabook'],
                            ['label' => 'All Laptops', 'category' => 'Laptop'],
                        ],
                    ],
                    [
                        'title' => 'Shop Laptops',
                        'links' => [
                            ['label' => 'MacBook', 'category' => 'MacBook'],
                            ['label' => 'Gaming Laptop', 'category' => 'Gaming Laptop'],
                            ['label' => 'Creator Laptop', 'category' => 'Creator Laptop'],
                            ['label' => 'Business Laptop', 'category' => 'Business Laptop'],
                            ['label' => 'Ultrabook', 'category' => 'Ultrabook'],
                        ],
                    ],
                ],
            ],
            'monitor' => [
                'label' => 'Monitor',
                'category' => 'Monitor',
                'cols' => [
                    [
                        'title' => 'By Type',
                        'links' => [
                            ['label' => 'Gaming Monitors', 'category' => 'Gaming Monitor'],
                            ['label' => 'Creator Monitors', 'category' => 'Creator Monitor'],
                            ['label' => 'Ultrawide Monitors', 'category' => 'Ultrawide Monitor'],
                            ['label' => '4K Monitors', 'category' => '4K Monitor'],
                            ['label' => 'All Monitors', 'category' => 'Monitor'],
                        ],
                    ],
                    [
                        'title' => 'Shop Monitors',
                        'links' => [
                            ['label' => 'All Monitors', 'category' => 'Monitor'],
                            ['label' => 'Gaming Monitor', 'category' => 'Gaming Monitor'],
                            ['label' => 'Creator Monitor', 'category' => 'Creator Monitor'],
                            ['label' => 'Ultrawide Monitor', 'category' => 'Ultrawide Monitor'],
                            ['label' => '4K Monitor', 'category' => '4K Monitor'],
                        ],
                    ],
                ],
            ],
            'apple' => [
                'label' => 'Apple',
                'category' => 'Apple',
                'cols' => [
                    [
                        'title' => 'Mac',
                        'links' => [
                            ['label' => 'MacBook', 'category' => 'MacBook'],
                            ['label' => 'Mac Desktop', 'category' => 'Mac Desktop'],
                            ['label' => 'iPhone', 'category' => 'iPhone'],
                            ['label' => 'iPad', 'category' => 'iPad'],
                            ['label' => 'All Apple Products', 'category' => 'Apple'],
                        ],
                    ],
                    [
                        'title' => 'Accessories',
                        'links' => [
                            ['label' => 'AirPods', 'category' => 'AirPods'],
                            ['label' => 'Apple Watch', 'category' => 'Apple Watch'],
                            ['label' => 'Mac Desktop', 'category' => 'Mac Desktop'],
                            ['label' => 'iPhone', 'category' => 'iPhone'],
                            ['label' => 'iPad', 'category' => 'iPad'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
