<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model;

use DateInterval;
use DateTimeImmutable;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class WarrantyCardProvider
{
    private ?array $cards = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductVisualResolver $productVisualResolver,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderItemCollectionFactory $orderItemCollectionFactory
    ) {
    }

    public function getCards(int $limit = 12): array
    {
        return array_slice($this->loadCards(), 0, $limit);
    }

    public function findByPhoneAndCode(string $phone, string $purchaseCode): ?array
    {
        $phone = $this->normalizePhone($phone);
        $purchaseCode = $this->normalizeCode($purchaseCode);

        if ($phone === '' || $purchaseCode === '') {
            return null;
        }

        foreach ($this->loadCards() as $card) {
            if ($card['phone_raw'] === $phone && $card['purchase_code_raw'] === $purchaseCode) {
                return $card;
            }
        }

        return null;
    }

    public function findByImei(string $imei): ?array
    {
        $imei = preg_replace('/\D+/', '', $imei) ?? '';
        if ($imei === '') {
            return null;
        }

        foreach ($this->loadCards() as $card) {
            if ($card['imei_raw'] === $imei) {
                return $card;
            }
        }

        return null;
    }

    public function findByOrderCode(string $orderCode): ?array
    {
        
        $code = strtoupper(trim($orderCode));
        if (str_starts_with($code, 'ORD')) {
            $code = substr($code, 3);
        }
        $incrementId = preg_replace('/[^0-9]/', '', $code);
        if ($incrementId === '') {
            return null;
        }

        
        $orders = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', ['in' => [$incrementId, str_pad($incrementId, 9, '0', STR_PAD_LEFT)]])
            ->setPageSize(1);
        $order = $orders->getFirstItem();
        if (!$order || !$order->getId()) {
            return null;
        }

        
        $items = $this->orderItemCollectionFactory->create()
            ->addFieldToFilter('order_id', $order->getId())
            ->addFieldToFilter('product_type', 'simple')
            ->setPageSize(1);
        $firstItem = $items->getFirstItem();
        $sku = $firstItem && $firstItem->getId() ? (string)$firstItem->getSku() : '';

        
        $card = null;
        if ($sku !== '') {
            foreach ($this->loadCards() as $c) {
                if ($c['sku'] === $sku) {
                    $card = $c;
                    break;
                }
            }
        }
        if (!$card) {
            $card = $this->loadCards()[0] ?? null;
        }
        if (!$card) {
            return null;
        }

        
        $seed = implode('|', [
            (string)$order->getIncrementId(),
            (string)$order->getEntityId(),
            (string)$order->getCustomerEmail(),
            'techieworld-purchase',
        ]);
        $raw = strtoupper(substr(hash('sha256', $seed), 0, 10));
        $purchaseCode = substr($raw, 0, 5) . '-' . substr($raw, 5);
        $orderCode = 'ORD' . $order->getIncrementId();

        return array_merge($card, [
            'purchase_code' => $purchaseCode,
            'purchase_code_raw' => str_replace('-', '', $purchaseCode),
            'order_code' => $orderCode,
        ]);
    }

    private function loadCards(): array
    {
        if ($this->cards !== null) {
            return $this->cards;
        }

        $store = $this->storeManager->getStore();
        $today = new DateTimeImmutable('today');
        $collection = $this->collectionFactory->create();
        $collection
            ->setStoreId((int) $store->getId())
            ->addStoreFilter($store)
            ->addAttributeToSelect(['name', 'sku', 'small_image', 'image', 'imei'])
            ->addUrlRewrite()
            ->addAttributeToFilter('status', 1)
            ->setVisibility($this->visibility->getVisibleInSiteIds())
            ->addAttributeToSort('entity_id', 'DESC')
            ->setPageSize(24)
            ->setCurPage(1);

        $cards = [];
        
        foreach ($collection as $product) {
            $cards[] = $this->buildCard($product, $today);
        }

        $this->cards = $cards;

        return $this->cards;
    }

    private function buildCard(Product $product, DateTimeImmutable $today): array
    {
        $purchaseDate = $today->sub(new DateInterval('P' . (30 + (($product->getId() * 13) % 240)) . 'D'));
        $months = 18 + (($product->getId() % 3) * 6);
        $expiresAt = $purchaseDate->add(new DateInterval('P' . $months . 'M'));
        $daysLeft = (int) $today->diff($expiresAt)->format('%r%a');
        $isActive = $daysLeft >= 0;
        $phoneRaw = $this->buildDigits($product, 10, 'phone');
        $imeiRaw = $this->resolveImei($product);
        $purchaseCodeRaw = $this->buildPurchaseCode($product);

        return [
            'product_name' => (string) $product->getName(),
            'sku' => (string) $product->getSku(),
            'product_url' => (string) $product->getProductUrl(),
            'image_url' => $this->productVisualResolver->resolveProductImage($product, 'product_page_image_small'),
            'phone' => $this->formatPhone($phoneRaw),
            'phone_raw' => $phoneRaw,
            'purchase_code' => $this->formatPurchaseCode($purchaseCodeRaw),
            'purchase_code_raw' => $purchaseCodeRaw,
            'imei' => $this->formatImei($imeiRaw),
            'imei_raw' => $imeiRaw,
            'purchase_date' => $purchaseDate->format('M d, Y'),
            'expires_at' => $expiresAt->format('M d, Y'),
            'warranty_months' => $months,
            'status' => $isActive ? 'Active warranty' : 'Expired',
            'status_class' => $isActive ? 'is-active' : 'is-expired',
            'days_left' => $daysLeft,
        ];
    }

    private function buildPurchaseCode(Product $product): string
    {
        return strtoupper(substr(hash('sha256', $product->getSku() . '|purchase-code'), 0, 10));
    }

    private function buildImei(Product $product): string
    {
        $base = $this->buildDigits($product, 14, 'imei');
        $sum = 0;
        $length = strlen($base);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $base[$length - 1 - $i];
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $base . ((10 - ($sum % 10)) % 10);
    }

    private function resolveImei(Product $product): string
    {
        $imei = preg_replace('/\D+/', '', (string) $product->getData('imei')) ?? '';

        if (strlen($imei) === 15) {
            return $imei;
        }

        return $this->buildImei($product);
    }

    private function buildDigits(Product $product, int $length, string $salt): string
    {
        $hash = hash('sha256', $product->getSku() . '|' . $product->getId() . '|' . $salt);
        $digits = '';

        foreach (str_split($hash) as $char) {
            $digits .= (string) (hexdec($char) % 10);
            if (strlen($digits) >= $length) {
                break;
            }
        }

        if ($salt === 'phone') {
            return '09' . substr($digits, 0, max(0, $length - 2));
        }

        return substr($digits, 0, $length);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($phone, '84') && strlen($phone) >= 11) {
            $phone = '0' . substr($phone, 2);
        }

        return $phone;
    }

    private function normalizeCode(string $purchaseCode): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $purchaseCode) ?? '');
    }

    private function formatPhone(string $phone): string
    {
        return preg_replace('/^(\d{4})(\d{3})(\d{3})$/', '$1 $2 $3', $phone) ?: $phone;
    }

    private function formatPurchaseCode(string $purchaseCode): string
    {
        return substr($purchaseCode, 0, 5) . '-' . substr($purchaseCode, 5);
    }

    private function formatImei(string $imei): string
    {
        return trim(chunk_split($imei, 3, ' '));
    }
}
