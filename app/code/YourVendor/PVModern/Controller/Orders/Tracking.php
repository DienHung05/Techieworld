<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Orders;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use YourVendor\PVModern\Model\Shipping\ShippingManager;

class Tracking implements HttpGetActionInterface
{
    private const PROVIDER_LABELS = [
        'ghn' => 'Giao Hang Nhanh (GHN)',
        'ghtk' => 'Giao Hang Tiet Kiem (GHTK)',
        'spx' => 'Shopee Express (SPX)',
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ShippingManager $shippingManager
    ) {
    }

    public function execute()
    {
        $orderId = trim((string) $this->request->getParam('order_id', ''));
        $provider = strtolower(trim((string) $this->request->getParam('provider', 'spx')));
        $trackingNumber = trim((string) $this->request->getParam('tracking_number', ''));

        if ($orderId === '') {
            return $this->resultJsonFactory->create()->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => 'Missing order_id.',
            ]);
        }

        if (!isset(self::PROVIDER_LABELS[$provider])) {
            $provider = 'spx';
        }

        if ($trackingNumber === '') {
            $trackingNumber = strtoupper($provider) . '-' . preg_replace('/[^A-Za-z0-9]/', '', $orderId) . '-MOCK';
        }

        $raw = $this->shippingManager->track($provider, $trackingNumber);
        $updatedAt = gmdate('c');
        $timeline = $this->normalizeTimeline((array) ($raw['timeline'] ?? []), $updatedAt);

        return $this->resultJsonFactory->create()->setData([
            'success' => true,
            'order_id' => $orderId,
            'carrier' => $provider,
            'carrier_label' => self::PROVIDER_LABELS[$provider],
            'tracking_number' => (string) ($raw['tracking_number'] ?? $trackingNumber),
            'status' => $this->humanizeStatus((string) ($raw['status'] ?? 'label_created')),
            'raw_status' => (string) ($raw['status'] ?? 'label_created'),
            'updated_at' => $updatedAt,
            'eta' => $this->estimateEta($provider),
            'timeline' => $timeline,
            'mock' => (bool) ($raw['mock'] ?? true),
        ]);
    }

    
    private function normalizeTimeline(array $timeline, string $fallbackTime): array
    {
        if ($timeline === []) {
            return [
                ['label' => 'Order received', 'time' => gmdate('c', strtotime('-3 hours'))],
                ['label' => 'Carrier label generated', 'time' => $fallbackTime],
            ];
        }

        $items = [];
        foreach ($timeline as $row) {
            $items[] = [
                'label' => (string) ($row['label'] ?? $row['status'] ?? 'Tracking update'),
                'time' => (string) ($row['time'] ?? $row['updated_at'] ?? $fallbackTime),
            ];
        }

        return $items;
    }

    private function estimateEta(string $provider): string
    {
        $days = match ($provider) {
            'ghn' => 2,
            'ghtk' => 4,
            default => 3,
        };

        return gmdate('Y-m-d', strtotime('+' . $days . ' days'));
    }

    private function humanizeStatus(string $status): string
    {
        return match ($status) {
            'created', 'label_created' => 'Đã tạo vận đơn',
            'picked_up' => 'Đã lấy hàng',
            'in_transit' => 'Đang vận chuyển',
            'delivered' => 'Đã giao hàng',
            'cancelled' => 'Đã hủy',
            default => 'Đang cập nhật',
        };
    }
}
