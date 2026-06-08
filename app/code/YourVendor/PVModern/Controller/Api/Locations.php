<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Api;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

class Locations implements HttpGetActionInterface
{
    private const PROVIDER_URL = 'https://provinces.open-api.vn/api/?depth=3';
    private const CACHE_FILE = 'pvmodern/vietnam-locations.json';

    public function __construct(
        private readonly JsonFactory $resultJsonFactory,
        private readonly DirectoryList $directoryList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $result->setHeader('Cache-Control', 'public, max-age=86400', true);

        $source = 'cache';
        $locations = $this->loadCachedLocations();
        if ($locations === []) {
            $source = 'provider';
            $locations = $this->loadProviderLocations();
            if ($locations !== []) {
                $this->saveCachedLocations($locations);
            }
        }
        if ($locations === []) {
            $source = 'fallback';
            $locations = $this->fallbackLocations();
        }

        return $result->setData([
            'success' => true,
            'locations' => $locations,
            'source' => $source,
            'mock' => $source === 'fallback',
        ]);
    }

    
    private function loadCachedLocations(): array
    {
        try {
            $path = $this->cachePath();
            if (!is_file($path)) {
                return [];
            }
            $payload = json_decode((string) file_get_contents($path), true);
            if (!is_array($payload)) {
                return [];
            }
            $locations = $payload['locations'] ?? $payload;
            return is_array($locations) ? $this->sanitizeLocations($locations) : [];
        } catch (\Throwable $exception) {
            $this->logger->warning('[PVModern][Locations] cache unavailable', [
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    
    private function saveCachedLocations(array $locations): void
    {
        try {
            $path = $this->cachePath();
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($path, json_encode([
                'generated_at' => gmdate('c'),
                'source' => self::PROVIDER_URL,
                'locations' => $locations,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (\Throwable $exception) {
            $this->logger->warning('[PVModern][Locations] cache write failed', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function cachePath(): string
    {
        return rtrim($this->directoryList->getPath(DirectoryList::VAR_DIR), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    
    private function loadProviderLocations(): array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 6,
                    'header' => "Accept: application/json\r\nUser-Agent: Techieworld-Magento/1.0\r\n",
                ],
            ]);
            $raw = @file_get_contents(self::PROVIDER_URL, false, $context);
            if (!$raw) {
                return [];
            }
            $rows = json_decode($raw, true);
            if (!is_array($rows)) {
                return [];
            }

            return $this->sanitizeLocations($rows);
        } catch (\Throwable $exception) {
            $this->logger->warning('[PVModern][Locations] provider unavailable', [
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    
    private function sanitizeLocations(array $rows): array
    {
        $locations = [];
        foreach ($rows as $province) {
            if (!is_array($province) || empty($province['name'])) {
                continue;
            }
            $districts = [];
            foreach (($province['districts'] ?? []) as $district) {
                if (!is_array($district) || empty($district['name'])) {
                    continue;
                }
                $wards = [];
                foreach (($district['wards'] ?? []) as $ward) {
                    if (is_array($ward) && !empty($ward['name'])) {
                        $wards[] = (string) $ward['name'];
                    } elseif (is_string($ward) && $ward !== '') {
                        $wards[] = $ward;
                    }
                }
                $districts[] = [
                    'name' => (string) $district['name'],
                    'wards' => $wards,
                ];
            }
            $locations[] = [
                'name' => (string) $province['name'],
                'districts' => $districts,
            ];
        }

        return $locations;
    }

    
    private function fallbackLocations(): array
    {
        return [
            [
                'name' => 'Thành phố Hồ Chí Minh',
                'districts' => [
                    ['name' => 'Quận 1', 'wards' => ['Phường Bến Nghé', 'Phường Bến Thành', 'Phường Đa Kao', 'Phường Nguyễn Thái Bình']],
                    ['name' => 'Quận 3', 'wards' => ['Phường Võ Thị Sáu', 'Phường 9', 'Phường 10', 'Phường 11']],
                    ['name' => 'Quận 7', 'wards' => ['Phường Tân Phong', 'Phường Tân Phú', 'Phường Tân Quy', 'Phường Phú Mỹ']],
                    ['name' => 'Bình Thạnh', 'wards' => ['Phường 1', 'Phường 11', 'Phường 19', 'Phường 22']],
                    ['name' => 'Thủ Đức', 'wards' => ['Phường Linh Trung', 'Phường Linh Xuân', 'Phường Hiệp Bình Chánh', 'Phường Thảo Điền']],
                ],
            ],
            [
                'name' => 'Thành phố Hà Nội',
                'districts' => [
                    ['name' => 'Hoàn Kiếm', 'wards' => ['Phường Hàng Bạc', 'Phường Hàng Bài', 'Phường Hàng Bông', 'Phường Tràng Tiền']],
                    ['name' => 'Ba Đình', 'wards' => ['Phường Điện Biên', 'Phường Đội Cấn', 'Phường Kim Mã', 'Phường Ngọc Hà']],
                    ['name' => 'Cầu Giấy', 'wards' => ['Phường Dịch Vọng', 'Phường Dịch Vọng Hậu', 'Phường Nghĩa Đô', 'Phường Yên Hòa']],
                    ['name' => 'Đống Đa', 'wards' => ['Phường Cát Linh', 'Phường Láng Hạ', 'Phường Ô Chợ Dừa', 'Phường Quang Trung']],
                ],
            ],
            [
                'name' => 'Thành phố Đà Nẵng',
                'districts' => [
                    ['name' => 'Hải Châu', 'wards' => ['Phường Hải Châu I', 'Phường Hải Châu II', 'Phường Bình Hiên', 'Phường Thạch Thang']],
                    ['name' => 'Sơn Trà', 'wards' => ['Phường An Hải Bắc', 'Phường Mân Thái', 'Phường Nại Hiên Đông', 'Phường Thọ Quang']],
                    ['name' => 'Thanh Khê', 'wards' => ['Phường Tam Thuận', 'Phường Thanh Khê Đông', 'Phường Thanh Khê Tây']],
                ],
            ],
            [
                'name' => 'Thành phố Cần Thơ',
                'districts' => [
                    ['name' => 'Ninh Kiều', 'wards' => ['Phường An Cư', 'Phường An Hòa', 'Phường Cái Khế', 'Phường Xuân Khánh']],
                    ['name' => 'Cái Răng', 'wards' => ['Phường Lê Bình', 'Phường Hưng Phú', 'Phường Ba Láng']],
                ],
            ],
        ];
    }
}
