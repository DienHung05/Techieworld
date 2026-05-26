<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Setup\Patch\Data;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;

class EnrichTechCatalog implements DataPatchInterface
{

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    public static function getDependencies(): array
    {
        return [SeedTechProducts::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $this->ensureCatalogAttributes($eavSetup);

            $store = $this->storeManager->getStore();
            $rootCategoryId = (int) $store->getRootCategoryId();
            $categoryMap = $this->ensureCategories($rootCategoryId);

            foreach ($this->getProductDefinitions() as $definition) {
                $this->upsertProduct($definition, $categoryMap, (int) $store->getWebsiteId());
            }

            $this->enrichExistingCatalog($categoryMap);
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }

        return $this;
    }

    private function ensureCatalogAttributes(\Magento\Eav\Setup\EavSetup $eavSetup): void
    {
        $entityType = Product::ENTITY;

        if (!$eavSetup->getAttributeId($entityType, 'brand')) {
            $eavSetup->addAttribute($entityType, 'brand', [
                'type' => 'varchar',
                'label' => 'Brand',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'searchable' => true,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => true,
                'used_in_product_listing' => true,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group' => 'General',
            ]);
        }

        if (!$eavSetup->getAttributeId($entityType, 'imei')) {
            $eavSetup->addAttribute($entityType, 'imei', [
                'type' => 'varchar',
                'label' => 'IMEI',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'searchable' => true,
                'filterable' => false,
                'comparable' => true,
                'visible_on_front' => true,
                'used_in_product_listing' => true,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group' => 'General',
                'note' => 'Unique product IMEI for warranty lookup.',
            ]);
        }

        if (!$eavSetup->getAttributeId($entityType, 'tech_specs')) {
            $eavSetup->addAttribute($entityType, 'tech_specs', [
                'type' => 'text',
                'label' => 'Technical Specifications',
                'input' => 'textarea',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group' => 'General',
                'note' => 'Line-based specification list in the format Label: Value.',
            ]);
        }
    }

    private function ensureCategories(int $rootCategoryId): array
    {
        $definitions = [
            ['name' => 'Desktop', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Laptop', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Monitor', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Apple', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'GPU', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'CPU', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'RAM', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'SSD', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'HDD', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Mainboard', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'PSU', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Heatsink', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Fan', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Case', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'Accessories', 'parent' => null, 'include_in_menu' => true],
            ['name' => 'MacBook', 'parent' => 'Apple', 'include_in_menu' => true],
            ['name' => 'Mac Desktop', 'parent' => 'Apple', 'include_in_menu' => true],
            ['name' => 'iPhone', 'parent' => 'Apple', 'include_in_menu' => true],
            ['name' => 'iPad', 'parent' => 'Apple', 'include_in_menu' => true],
            ['name' => 'AirPods', 'parent' => 'Apple', 'include_in_menu' => true],
            ['name' => 'Apple Watch', 'parent' => 'Apple', 'include_in_menu' => true],
            ['name' => 'Gaming Laptop', 'parent' => 'Laptop', 'include_in_menu' => true],
            ['name' => 'Creator Laptop', 'parent' => 'Laptop', 'include_in_menu' => true],
            ['name' => 'Business Laptop', 'parent' => 'Laptop', 'include_in_menu' => true],
            ['name' => 'Ultrabook', 'parent' => 'Laptop', 'include_in_menu' => true],
            ['name' => 'Gaming Monitor', 'parent' => 'Monitor', 'include_in_menu' => true],
            ['name' => 'Creator Monitor', 'parent' => 'Monitor', 'include_in_menu' => true],
            ['name' => 'Ultrawide Monitor', 'parent' => 'Monitor', 'include_in_menu' => true],
            ['name' => '4K Monitor', 'parent' => 'Monitor', 'include_in_menu' => true],
        ];

        $categoryMap = [];
        foreach ($definitions as $definition) {
            $parentId = $definition['parent'] === null
                ? $rootCategoryId
                : ($categoryMap[$this->normalizeKey($definition['parent'])] ?? $rootCategoryId);

            $category = $this->getOrCreateCategory(
                $definition['name'],
                $parentId,
                (bool) $definition['include_in_menu']
            );

            $categoryMap[$this->normalizeKey($definition['name'])] = (int) $category->getId();
        }

        return $categoryMap;
    }

    private function getOrCreateCategory(string $name, int $parentId, bool $includeInMenu): Category
    {
        $category = $this->findCategoryByName($name, $parentId);
        if ($category === null) {
            $category = $this->categoryFactory->create();
            $category->setParentId($parentId);
            $parentCategory = $this->categoryFactory->create()->load($parentId);
            if ($parentCategory->getId()) {
                $category->setPath((string) $parentCategory->getPath());
            }
        } else {
            $parentCategory = $this->categoryFactory->create()->load($parentId);
            if ($parentCategory->getId() && $category->getId()) {
                $category->setPath(rtrim((string) $parentCategory->getPath(), '/') . '/' . (int) $category->getId());
            }
        }

        $category->setName($name);
        $category->setIsActive(true);
        $category->setIncludeInMenu($includeInMenu);

        if (!$category->getId()) {
            $category->setDisplayMode('PRODUCTS');
            $category->setIsAnchor(1);
        }

        $category->save();

        return $category;
    }

    private function findCategoryByName(string $name, int $parentId): ?Category
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection
            ->addAttributeToSelect(['name', 'include_in_menu'])
            ->addAttributeToFilter('parent_id', $parentId);

        $needle = $this->normalizeKey($name);
        foreach ($collection as $category) {
            if ($this->normalizeKey((string) $category->getName()) === $needle) {
                return $category;
            }
        }

        return null;
    }

    private function upsertProduct(array $definition, array $categoryMap, int $websiteId): void
    {
        try {
            $product = $this->productRepository->get($definition['sku'], true, 0, true);
        } catch (NoSuchEntityException) {
            $product = $this->productFactory->create();
            $product->setSku($definition['sku']);
            $product->setAttributeSetId(4);
            $product->setTypeId(Type::TYPE_SIMPLE);
            $product->setVisibility(Visibility::VISIBILITY_BOTH);
            $product->setStoreId(0);
            $product->setWebsiteIds([$websiteId]);
        }

        $categoryIds = [];
        foreach ($definition['categories'] as $categoryName) {
            $categoryKey = $this->normalizeKey($categoryName);
            if (!empty($categoryMap[$categoryKey])) {
                $categoryIds[] = (int) $categoryMap[$categoryKey];
            }
        }

        $product->setName($definition['name']);
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setPrice($this->normalizeUsdPrice((float) $definition['price']));
        $product->setWeight((float) ($definition['weight'] ?? 1));
        $product->setTaxClassId(2);
        $product->setData('brand', $definition['brand']);
        $product->setShortDescription($definition['short_description']);
        $product->setDescription($definition['description']);
        $product->setMetaTitle($definition['name']);
        $product->setMetaKeyword($definition['brand'] . ', ' . implode(', ', $definition['categories']));
        $product->setMetaDescription(strip_tags($definition['short_description']));
        $product->setData('imei', $definition['imei'] ?? $this->buildImei($definition['sku']));
        $product->setData('tech_specs', $definition['tech_specs'] ?? $this->buildTechSpecs($definition));
        $product->setCategoryIds(array_values(array_unique($categoryIds)));

        if (array_key_exists('special_price', $definition)) {
            if ($definition['special_price'] !== null) {
                $product->setSpecialPrice($this->normalizeUsdPrice((float) $definition['special_price']));
            } else {
                $product->setSpecialPrice(null);
            }
        }

        $this->productRepository->save($product);
        $this->categoryLinkManagement->assignProductToCategories((string) $product->getSku(), array_values(array_unique($categoryIds)));

        $stockItem = $this->stockRegistry->getStockItemBySku($definition['sku']);
        $qty = (float) $definition['qty'];
        $stockItem->setQty($qty);
        $stockItem->setIsInStock($qty > 0);
        $stockItem->setManageStock(true);
        $this->stockRegistry->updateStockItemBySku($definition['sku'], $stockItem);
    }

    private function enrichExistingCatalog(array $categoryMap): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'brand', 'imei', 'price', 'special_price']);
        $managedCategoryIds = array_map('intval', array_values($categoryMap));

        foreach ($collection as $product) {
            $categoryIds = array_values(array_diff(array_map('intval', (array) $product->getCategoryIds()), $managedCategoryIds));

            foreach ($this->inferCategoryNames($product) as $categoryName) {
                $key = $this->normalizeKey($categoryName);
                if (!empty($categoryMap[$key])) {
                    $categoryIds[] = (int) $categoryMap[$key];
                }
            }

            $product->setCategoryIds(array_values(array_unique($categoryIds)));

            if (!(string) $product->getData('imei')) {
                $product->setData('imei', $this->buildImei((string) $product->getSku()));
            }

            if (!(string) $product->getData('brand')) {
                $product->setData('brand', $this->inferBrand($product));
            }

            $currentPrice = (float) $product->getPrice();
            $product->setPrice($this->normalizeUsdPrice($currentPrice));

            $specialPrice = $product->getData('special_price');
            if ($specialPrice !== null && $specialPrice !== '') {
                $product->setSpecialPrice($this->normalizeUsdPrice((float) $specialPrice));
            }

            $this->productRepository->save($product);
            $this->categoryLinkManagement->assignProductToCategories((string) $product->getSku(), array_values(array_unique($categoryIds)));
        }
    }

    private function inferCategoryNames(Product $product): array
    {
        $name = mb_strtolower((string) $product->getName());
        $sku = strtoupper(trim((string) $product->getSku()));
        $currentNames = array_map(
            static fn ($value): string => mb_strtolower((string) $value),
            $product->getCategoryCollection()->addAttributeToSelect('name')->getColumnValues('name')
        );
        $haystack = trim($name . ' ' . $sku . ' ' . implode(' ', $currentNames));
        $categories = [];

        if ($this->skuStartsWithAny($sku, ['APL-IPHONE-']) || $this->containsAny($haystack, ['iphone'])) {
            return ['Apple', 'iPhone'];
        }
        if ($this->skuStartsWithAny($sku, ['APL-IPAD-']) || $this->containsAny($haystack, ['ipad'])) {
            return ['Apple', 'iPad'];
        }
        if ($this->skuStartsWithAny($sku, ['APL-AIRPODS']) || $this->containsAny($haystack, ['airpods'])) {
            return ['Apple', 'AirPods'];
        }
        if ($this->skuStartsWithAny($sku, ['APL-WATCH-']) || $this->containsAny($haystack, ['apple watch', 'watch ultra', 'watch series'])) {
            return ['Apple', 'Apple Watch'];
        }

        if (
            $this->skuStartsWithAny($sku, ['MON-'])
            || (
                $this->containsAny($haystack, ['monitor', 'display', 'ultragear', 'odyssey', 'alienware aw', 'ultrasharp'])
                && !$this->containsAny($haystack, ['laptop', 'notebook', 'macbook'])
            )
        ) {
            $categories[] = 'Monitor';
            if ($this->containsAny($haystack, ['165hz', '175hz', '240hz', '360hz', 'gaming monitor', 'alienware aw', 'odyssey', 'ultragear'])) {
                $categories[] = 'Gaming Monitor';
            }
            if ($this->containsAny($haystack, ['proart', 'creator monitor', 'ultrasharp', 'studio display'])) {
                $categories[] = 'Creator Monitor';
            }
            if ($this->containsAny($haystack, ['ultrawide', '34', '38', '49', 'qd oled'])) {
                $categories[] = 'Ultrawide Monitor';
            }
            if ($this->containsAny($haystack, ['4k', '5k', 'uhd'])) {
                $categories[] = '4K Monitor';
            }
            if ($this->containsAny($haystack, ['apple', 'studio display'])) {
                $categories[] = 'Apple';
            }

            return array_values(array_unique($categories));
        }

        if ($this->skuStartsWithAny($sku, ['DESK-APPLE-']) || $this->containsAny($haystack, ['mac studio', 'mac mini', 'imac'])) {
            return ['Desktop', 'Apple', 'Mac Desktop'];
        }

        if ($this->skuStartsWithAny($sku, ['DESK-'])) {
            return ['Desktop'];
        }

        if ($this->skuStartsWithAny($sku, ['LAP-APPLE-']) || $this->containsAny($haystack, ['macbook'])) {
            $categories = ['Laptop', 'Apple', 'MacBook'];
            if ($this->containsAny($haystack, ['macbook air'])) {
                $categories[] = 'Ultrabook';
            }
            if ($this->containsAny($haystack, ['macbook pro'])) {
                $categories[] = 'Creator Laptop';
            }

            return array_values(array_unique($categories));
        }

        if (
            $this->skuStartsWithAny($sku, ['LAP-'])
            || $this->containsAny($haystack, ['laptop', 'notebook', 'xps', 'zephyrus', 'predator', 'legion', 'raider', 'thinkpad', 'elitebook', 'expertbook', 'latitude'])
        ) {
            $categories[] = 'Laptop';
            if ($this->containsAny($haystack, ['rog', 'zephyrus', 'predator', 'legion', 'raider', 'strix', 'tuf', 'omen'])) {
                $categories[] = 'Gaming Laptop';
            }
            if ($this->containsAny($haystack, ['xps', 'creator', 'proart'])) {
                $categories[] = 'Creator Laptop';
            }
            if ($this->containsAny($haystack, ['thinkpad', 'elitebook', 'expertbook', 'latitude'])) {
                $categories[] = 'Business Laptop';
            }
            if ($this->containsAny($haystack, ['ultrabook', 'x1 carbon', 'zenbook', 'elitebook', 'expertbook'])) {
                $categories[] = 'Ultrabook';
            }

            return array_values(array_unique($categories));
        }

        if ($this->skuStartsWithAny($sku, ['GPU-', 'VGA-']) || $this->containsAny($haystack, ['geforce', 'radeon', 'graphics card', 'rtx'])) {
            return ['Desktop', 'GPU'];
        }
        if (
            $this->skuStartsWithAny($sku, ['CPU-'])
            || (
                $this->containsAny($haystack, ['cpu', 'ryzen', 'threadripper', 'processor', 'intel core'])
                && !$this->containsAny($haystack, ['cooler', 'aio', 'radiator'])
            )
        ) {
            return ['Desktop', 'CPU'];
        }
        if ($this->skuStartsWithAny($sku, ['RAM-']) || $this->containsAny($haystack, ['ddr4', 'ddr5', 'memory kit'])) {
            return ['Desktop', 'RAM'];
        }
        if ($this->skuStartsWithAny($sku, ['SSD-']) || $this->containsAny($haystack, ['ssd', 'nvme', 'm 2'])) {
            return ['Desktop', 'SSD'];
        }
        if ($this->skuStartsWithAny($sku, ['HDD-']) || $this->containsAny($haystack, ['hdd', 'hard drive'])) {
            return ['Desktop', 'HDD'];
        }
        if ($this->skuStartsWithAny($sku, ['MB-', 'MAINBOARD-']) || $this->containsAny($haystack, ['mainboard', 'motherboard', 'aorus', 'strix x870e', 'carbon wifi'])) {
            return ['Desktop', 'Mainboard'];
        }
        if ($this->skuStartsWithAny($sku, ['PSU-']) || $this->containsAny($haystack, ['power supply', 'psu'])) {
            return ['Desktop', 'PSU'];
        }
        if ($this->skuStartsWithAny($sku, ['COOL-']) || $this->containsAny($haystack, ['cpu cooler', 'aio', 'radiator', 'heatsink', 'nh d15'])) {
            return ['Desktop', 'Heatsink'];
        }
        if ($this->skuStartsWithAny($sku, ['FAN-']) || $this->containsAny($haystack, ['case fan', '120mm fan', '140mm fan'])) {
            return ['Desktop', 'Fan'];
        }
        if (
            $this->skuStartsWithAny($sku, ['CASE-'])
            || ($this->containsAny($haystack, ['pc case', 'chassis', 'mid tower', 'full tower']) && !$this->containsAny($haystack, ['case fan']))
        ) {
            return ['Desktop', 'Case'];
        }

        if (
            $this->containsAny($haystack, ['desktop', 'workstation', 'prebuilt'])
            && !$this->skuStartsWithAny($sku, ['GPU-', 'VGA-', 'CPU-', 'MB-', 'MAINBOARD-', 'RAM-', 'SSD-', 'HDD-', 'PSU-', 'COOL-', 'FAN-', 'CASE-'])
        ) {
            return ['Desktop'];
        }

        return array_values(array_unique($categories));
    }

    private function inferBrand(Product $product): string
    {
        $name = (string) $product->getName();
        foreach (['AMD', 'ASUS', 'Gigabyte', 'Apple', 'Dell', 'Samsung', 'LG', 'Corsair', 'Lenovo', 'MSI', 'Acer', 'Noctua', 'Ugreen', 'Seagate', 'Fractal', 'NZXT', 'Lian Li'] as $brand) {
            if (stripos($name, $brand) !== false) {
                return $brand;
            }
        }

        return 'Techieworld';
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        $haystack = ' ' . $this->normalizeSearchTerms($haystack) . ' ';

        foreach ($needles as $needle) {
            $needle = trim($this->normalizeSearchTerms((string) $needle));
            if ($needle !== '' && str_contains($haystack, ' ' . $needle . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function skuStartsWithAny(string $sku, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($sku, strtoupper($prefix))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSearchTerms(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalizeKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? $value;

        return trim($value, '-');
    }

    private function normalizeUsdPrice(float $value): float
    {
        // Store currency is VND. Prices in the catalog definitions are already VND
        // integers (e.g. 19,890,000). Clamp to the displayable range and return as-is.
        if ($value <= 0) {
            return 0.0;
        }
        return round($value, 0);
    }

    private function buildTechSpecs(array $definition): string
    {
        $name = (string) ($definition['name'] ?? '');
        $categories = (array) ($definition['categories'] ?? []);
        $lowerName = mb_strtolower($name);

        $rows = [
            'Brand' => (string) ($definition['brand'] ?? 'Techieworld'),
            'Model' => $name,
            'SKU' => (string) ($definition['sku'] ?? ''),
        ];

        if ($categories !== []) {
            $rows['Category'] = implode(' / ', $categories);
        }

        if ($this->containsAny($lowerName, ['cpu', 'ryzen', 'intel core', 'processor'])) {
            $rows['Socket'] = $this->matchOrDefault($name, '/\b(AM5|AM4|LGA\s?1851|LGA\s?1700)\b/i', 'Desktop');
            $rows['Core / Thread'] = $this->matchOrDefault($name, '/(\d+\s*Cores?\s*\d+\s*Threads?|\d+C\/\d+T)/i', 'High-performance configuration');
            $rows['Boost Clock'] = $this->matchOrDefault($name, '/(Up\s+[0-9.]+\s*GHz|[0-9.]+\s*GHz)/i', 'Turbo-class');
        } elseif ($this->containsAny($lowerName, ['geforce', 'rtx', 'radeon', 'graphics'])) {
            $rows['GPU Family'] = $this->matchOrDefault($name, '/(RTX\s?\d{4,5}\s?(?:Ti|SUPER)?|Radeon\s?[A-Z0-9 ]+)/i', 'Discrete graphics');
            $rows['Memory'] = $this->matchOrDefault($name, '/\b\d+\s*GB\b/i', 'High-speed VRAM');
            $rows['Cooling'] = 'Triple-fan enthusiast design';
        } elseif ($this->containsAny($lowerName, ['macbook', 'laptop', 'notebook', 'xps', 'legion', 'latitude', 'zenbook', 'expertbook', 'predator', 'raider'])) {
            $rows['Display'] = $this->matchOrDefault($name, '/\b(13|14|15|16|18)\b/', 'Premium') . '-inch';
            $rows['Memory'] = $this->matchOrDefault($name, '/\b(16GB|24GB|32GB|48GB|64GB)\b/i', '16GB');
            $rows['Storage'] = $this->matchOrDefault($name, '/\b(256GB|512GB|1TB|2TB)\b/i', '512GB SSD');
        } elseif ($this->containsAny($lowerName, ['monitor', 'odyssey', 'ultragear', 'proart', 'viewfinity', 'ultrasharp', 'studio display'])) {
            $rows['Panel Size'] = $this->matchOrDefault($name, '/\b(27|32|34|40|45|49)\b/', '32') . '-inch';
            $rows['Resolution'] = $this->matchOrDefault($name, '/(5K|4K|Dual UHD|QHD|WQHD)/i', 'High resolution');
            $rows['Refresh Rate'] = $this->matchOrDefault($name, '/\b(60Hz|120Hz|144Hz|165Hz|175Hz|240Hz)\b/i', 'Professional refresh');
        } elseif ($this->containsAny($lowerName, ['iphone', 'ipad', 'airpods', 'watch'])) {
            $rows['Storage / Variant'] = $this->matchOrDefault($name, '/\b(128GB|256GB|512GB|1TB|42mm|44mm|46mm)\b/i', 'Standard');
            $rows['Connectivity'] = 'Official Apple retail version';
            $rows['Warranty Lookup'] = 'Protected by IMEI / purchase code';
        } elseif ($this->containsAny($lowerName, ['ram', 'ddr5', 'ddr4', 'memory'])) {
            $rows['Capacity'] = $this->matchOrDefault($name, '/\b(16GB|32GB|48GB|64GB)\b/i', '32GB');
            $rows['Speed'] = $this->matchOrDefault($name, '/\b(5600|6000|6200|6400|7200)\b/i', '6000') . ' MT/s';
            $rows['Memory Type'] = $this->matchOrDefault($name, '/\bDDR[45]\b/i', 'DDR5');
        } elseif ($this->containsAny($lowerName, ['ssd', 'nvme', 'firecuda'])) {
            $rows['Capacity'] = $this->matchOrDefault($name, '/\b(1TB|2TB|4TB)\b/i', '1TB');
            $rows['Interface'] = $this->matchOrDefault($name, '/(Gen4|Gen5|PCIe\s*Gen4|PCIe\s*Gen5)/i', 'PCIe Gen4 NVMe');
            $rows['Form Factor'] = 'M.2 2280';
        } elseif ($this->containsAny($lowerName, ['hdd', 'ironwolf', 'exos', 'skyhawk'])) {
            $rows['Capacity'] = $this->matchOrDefault($name, '/\b(4TB|8TB|10TB|12TB|20TB)\b/i', '8TB');
            $rows['Drive Type'] = '3.5-inch SATA HDD';
            $rows['RPM'] = $this->matchOrDefault($name, '/\b(5400RPM|7200RPM)\b/i', '7200RPM');
        } elseif ($this->containsAny($lowerName, ['psu', 'power supply'])) {
            $rows['Power'] = $this->matchOrDefault($name, '/\b(850W|1000W|1200W|1500W)\b/i', '850W');
            $rows['Efficiency'] = $this->matchOrDefault($name, '/80\s*Plus\s*(Gold|Platinum|Titanium)/i', '80 Plus Gold');
            $rows['Modularity'] = 'Fully modular';
        } elseif ($this->containsAny($lowerName, ['cooler', 'aio', 'h100i', 'h150i', 'titan', 'nh-d15'])) {
            $rows['Cooling Type'] = $this->containsAny($lowerName, ['nh-d15']) ? 'Tower air cooler' : 'AIO liquid cooler';
            $rows['Radiator / Height'] = $this->matchOrDefault($name, '/\b(240mm|280mm|360mm|168mm)\b/i', '360mm');
            $rows['Lighting'] = 'ARGB / performance tuned';
        } elseif ($this->containsAny($lowerName, ['fan', 'qx120', 'rx120', 'lx120', 'rs120'])) {
            $rows['Pack'] = $this->matchOrDefault($name, '/(Single|Triple Pack)/i', 'Triple Pack');
            $rows['Fan Size'] = $this->matchOrDefault($name, '/\b(120|140)\b/i', '120') . 'mm';
            $rows['Lighting'] = 'ARGB';
        } elseif ($this->containsAny($lowerName, ['case', 'airflow', 'tempered glass', 'dual chamber'])) {
            $rows['Chassis Type'] = $this->containsAny($lowerName, ['dual chamber']) ? 'Dual chamber' : 'Mid-tower';
            $rows['Side Panel'] = 'Tempered glass';
            $rows['Cooling Focus'] = 'High-airflow layout';
        } elseif ($this->containsAny($lowerName, ['mac studio', 'mac mini', 'imac', 'desktop', 'corsair one', 'rog g22'])) {
            $rows['Form Factor'] = 'Compact desktop';
            $rows['Memory'] = $this->matchOrDefault($name, '/\b(16GB|24GB|32GB|64GB|96GB)\b/i', '24GB');
            $rows['Storage'] = $this->matchOrDefault($name, '/\b(512GB|1TB|2TB)\b/i', '512GB');
        }

        $lines = [];
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $lines[] = $label . ': ' . $value;
        }

        return implode("\n", array_slice($lines, 0, 8));
    }

    private function matchOrDefault(string $value, string $pattern, string $default): string
    {
        if (preg_match($pattern, $value, $matches)) {
            return trim((string) ($matches[1] ?? $matches[0]));
        }

        return $default;
    }

    private function buildImei(string $seed): string
    {
        $hash = hash('sha256', $seed . '|imei');
        $digits = '';

        foreach (str_split($hash) as $character) {
            $digits .= (string) (hexdec($character) % 10);
            if (strlen($digits) >= 14) {
                break;
            }
        }

        $sum = 0;
        $length = strlen($digits);
        for ($index = 0; $index < $length; $index++) {
            $digit = (int) $digits[$length - 1 - $index];
            if ($index % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $digits . ((10 - ($sum % 10)) % 10);
    }

    private function getProductDefinitions(): array
    {
        return [
            [
                'sku' => 'CPU-AMD-Ryzen 9 9950X3D-AM5',
                'name' => 'CPU AMD Ryzen 9 9950X3D (Up 5.7 GHz, 16 Cores 32 Threads, 128MB Cache, AM5)',
                'brand' => 'AMD',
                'price' => 19890000,
                'special_price' => 16990000,
                'qty' => 9,
                'categories' => ['Desktop', 'CPU'],
                'short_description' => 'Zen 5 flagship processor for premium AM5 gaming and creator builds.',
                'description' => '<p>AMD Ryzen 9 9950X3D delivers top-tier gaming throughput, heavy creator performance, and efficient AM5 platform longevity for premium desktop builds.</p>',
            ],
            [
                'sku' => 'VGA-ASUS-RTX5090-OC',
                'name' => 'Card ASUS ROG Astral GeForce RTX 5090 OC Edition 32GB GDDR7',
                'brand' => 'ASUS',
                'price' => 94990000,
                'special_price' => 75990000,
                'qty' => 3,
                'categories' => ['Desktop', 'GPU'],
                'short_description' => 'Extreme flagship ASUS graphics card tuned for 4K, AI, and workstation-grade rendering.',
                'description' => '<p>ROG Astral RTX 5090 OC Edition pairs flagship GDDR7 bandwidth with a heavy-duty cooling design for elite gaming, AI workloads, and production rendering.</p>',
            ],
            [
                'sku' => 'Mainboard-Gigabyte-Z890-AORUS-EXTREME',
                'name' => 'Mainboard Gigabyte Z890 AORUS XTREME AI TOP',
                'brand' => 'Gigabyte',
                'price' => 26990000,
                'special_price' => 23990000,
                'qty' => 6,
                'categories' => ['Desktop', 'Mainboard'],
                'short_description' => 'Flagship Intel Z890 motherboard with AI tuning, premium power, and next-gen connectivity.',
                'description' => '<p>Gigabyte Z890 AORUS XTREME AI TOP is built for Intel enthusiast systems with robust VRM delivery, premium thermals, and flagship networking.</p>',
            ],
            [
                'sku' => 'DESK-TW-CREATOR-01',
                'name' => 'Corsair ONE i500 Ultra 9 RTX 4080 SUPER',
                'brand' => 'Corsair',
                'price' => 52990000,
                'special_price' => 45990000,
                'qty' => 7,
                'categories' => ['Desktop'],
                'short_description' => 'Flagship compact desktop with strong creator and gaming performance in a premium chassis.',
                'description' => '<p>Corsair ONE i500 combines Intel Core Ultra class performance with RTX graphics in a premium compact desktop design.</p>',
            ],
            [
                'sku' => 'DESK-TW-WORKSTATION-01',
                'name' => 'ASUS ROG G22CH Core i7 RTX 4070 SUPER',
                'brand' => 'ASUS',
                'price' => 33990000,
                'special_price' => 28990000,
                'qty' => 5,
                'categories' => ['Desktop'],
                'short_description' => 'Compact performance desktop for coding, content creation, and fast 1440p gaming.',
                'description' => '<p>ROG G22CH packages desktop-class gaming performance into a compact tower suited for premium workspaces.</p>',
            ],
            [
                'sku' => 'LAP-APPLE-MBA15-M3',
                'name' => 'Apple MacBook Air 15 M3 16GB 512GB',
                'brand' => 'Apple',
                'price' => 41990000,
                'special_price' => 35990000,
                'qty' => 12,
                'categories' => ['Laptop', 'Apple', 'MacBook', 'Ultrabook'],
                'short_description' => 'Thin-and-light Apple notebook with a large display, long battery life, and strong M3 everyday performance.',
                'description' => '<p>MacBook Air 15 with Apple M3 is ideal for students, developers, and mobile creators who want a larger screen without giving up portability.</p>',
            ],
            [
                'sku' => 'LAP-APPLE-MBP14-M4PRO',
                'name' => 'Apple MacBook Pro 14 M4 Pro 24GB 1TB',
                'brand' => 'Apple',
                'price' => 63990000,
                'special_price' => 54990000,
                'qty' => 8,
                'categories' => ['Laptop', 'Apple', 'MacBook', 'Creator Laptop'],
                'short_description' => 'Professional Apple notebook for development, editing, and demanding creator workflows.',
                'description' => '<p>MacBook Pro 14 with M4 Pro combines a bright Liquid Retina XDR display, strong sustained performance, and long battery life for serious professional work.</p>',
            ],
            [
                'sku' => 'DESK-APPLE-MAC-STUDIO-M3U',
                'name' => 'Apple Mac Studio M3 Ultra 96GB 2TB',
                'brand' => 'Apple',
                'price' => 119990000,
                'special_price' => null,
                'qty' => 3,
                'categories' => ['Desktop', 'Apple', 'Mac Desktop'],
                'short_description' => 'High-density Apple desktop for rendering, audio, and advanced production pipelines.',
                'description' => '<p>Mac Studio M3 Ultra delivers Apple silicon workstation-class performance in a compact desktop made for video, 3D, code compilation, and pro audio.</p>',
            ],
            [
                'sku' => 'APL-IPHONE-16PM-256',
                'name' => 'Apple iPhone 16 Pro Max 256GB Natural Titanium',
                'brand' => 'Apple',
                'price' => 36990000,
                'special_price' => 31990000,
                'qty' => 10,
                'categories' => ['Apple', 'iPhone'],
                'short_description' => 'Flagship iPhone with a premium display, fast camera pipeline, and strong everyday battery life.',
                'description' => '<p>iPhone 16 Pro Max offers Apple’s best large-format mobile experience with premium cameras, smooth performance, and a titanium build.</p>',
            ],
            [
                'sku' => 'APL-IPAD-PRO13-M4-256',
                'name' => 'Apple iPad Pro 13 M4 256GB Wi-Fi',
                'brand' => 'Apple',
                'price' => 35990000,
                'special_price' => 28990000,
                'qty' => 9,
                'categories' => ['Apple', 'iPad'],
                'short_description' => 'Ultra-thin iPad Pro for drawing, note-taking, content review, and premium mobile workflows.',
                'description' => '<p>iPad Pro 13 with Apple M4 combines a tandem OLED display with flagship tablet performance for creators, students, and mobile professionals.</p>',
            ],
            [
                'sku' => 'APL-AIRPODS-PRO2-USBC',
                'name' => 'Apple AirPods Pro 2 USB-C',
                'brand' => 'Apple',
                'price' => 6290000,
                'special_price' => 4690000,
                'qty' => 25,
                'categories' => ['Apple', 'AirPods', 'Accessories'],
                'short_description' => 'Premium Apple true wireless earbuds with ANC, transparency, and seamless ecosystem pairing.',
                'description' => '<p>AirPods Pro 2 with USB-C bring flagship active noise cancellation, improved comfort, and strong Apple ecosystem integration for daily listening.</p>',
            ],
            [
                'sku' => 'APL-WATCH-S10-46',
                'name' => 'Apple Watch Series 10 46mm GPS Aluminum',
                'brand' => 'Apple',
                'price' => 11990000,
                'special_price' => 8990000,
                'qty' => 14,
                'categories' => ['Apple', 'Apple Watch'],
                'short_description' => 'Premium Apple smartwatch focused on health tracking, notifications, and everyday convenience.',
                'description' => '<p>Apple Watch Series 10 balances health metrics, bright always-on viewing, and strong day-to-day usability for active Apple users.</p>',
            ],
            [
                'sku' => 'LAP-ASUS-ZEPHYRUS-G16-4070',
                'name' => 'ASUS ROG Zephyrus G16 Core Ultra 9 RTX 4070 OLED',
                'brand' => 'ASUS',
                'price' => 57990000,
                'special_price' => 45990000,
                'qty' => 6,
                'categories' => ['Laptop', 'Gaming Laptop'],
                'short_description' => 'Premium gaming laptop with RTX graphics, OLED visuals, and creator-friendly portability.',
                'description' => '<p>ROG Zephyrus G16 blends Intel Core Ultra speed, RTX 4070 graphics, and an OLED panel for gaming, streaming, and content production.</p>',
            ],
            [
                'sku' => 'LAP-DELL-XPS16-9640',
                'name' => 'Dell XPS 16 9640 Core Ultra 7 RTX 4060 OLED',
                'brand' => 'Dell',
                'price' => 68990000,
                'special_price' => null,
                'qty' => 4,
                'categories' => ['Laptop', 'Creator Laptop'],
                'short_description' => 'Premium creator notebook with a large OLED panel and refined build quality.',
                'description' => '<p>Dell XPS 16 is built for premium productivity and creative work, pairing a clean chassis with strong Intel and RTX hardware.</p>',
            ],
            [
                'sku' => 'LAP-LENOVO-LEGION7I-4080',
                'name' => 'Lenovo Legion 7i Gen 9 Core i9 RTX 4080',
                'brand' => 'Lenovo',
                'price' => 69990000,
                'special_price' => 57990000,
                'qty' => 5,
                'categories' => ['Laptop', 'Gaming Laptop'],
                'short_description' => 'High-performance Lenovo gaming notebook for AAA play, streaming, and heavy multitasking.',
                'description' => '<p>Legion 7i Gen 9 offers flagship Intel and RTX-class gaming hardware in a premium design tuned for cooling and sustained power.</p>',
            ],
            [
                'sku' => 'MON-SAMSUNG-ODYSSEY-G8-OLED',
                'name' => 'Samsung Odyssey OLED G8 32 4K 240Hz',
                'brand' => 'Samsung',
                'price' => 29990000,
                'special_price' => 23990000,
                'qty' => 7,
                'categories' => ['Monitor', 'Gaming Monitor', '4K Monitor'],
                'short_description' => 'Premium Samsung 4K OLED gaming display with deep contrast and high refresh.',
                'description' => '<p>Odyssey OLED G8 is built for premium gaming with deep blacks, 4K sharpness, and 240Hz responsiveness in a premium desktop setup.</p>',
            ],
            [
                'sku' => 'MON-LG-32GS95UE-OLED',
                'name' => 'LG UltraGear 32GS95UE 32 4K OLED 240Hz',
                'brand' => 'LG',
                'price' => 31990000,
                'special_price' => 27990000,
                'qty' => 6,
                'categories' => ['Monitor', 'Gaming Monitor', '4K Monitor'],
                'short_description' => 'Flagship LG OLED monitor for immersive gaming and premium desktop color.',
                'description' => '<p>LG UltraGear 32GS95UE targets enthusiasts who want OLED contrast, responsive high refresh gaming, and detailed 4K clarity.</p>',
            ],
            [
                'sku' => 'MON-ASUS-PROART-PA32',
                'name' => 'ASUS ProArt PA32UCXR 32 4K Mini-LED',
                'brand' => 'ASUS',
                'price' => 45990000,
                'special_price' => null,
                'qty' => 3,
                'categories' => ['Monitor', 'Creator Monitor', '4K Monitor'],
                'short_description' => 'Color-accurate creator monitor designed for grading, photography, and studio review.',
                'description' => '<p>ASUS ProArt PA32UCXR focuses on calibrated color, creator workflows, and professional review environments where precision matters.</p>',
            ],
            [
                'sku' => 'CPU-AMD-Ryzen 9 9900X-AM5',
                'name' => 'CPU AMD Ryzen 9 9900X (Up 5.6 GHz, 12 Cores 24 Threads, AM5)',
                'brand' => 'AMD',
                'price' => 15490000,
                'special_price' => 12990000,
                'qty' => 18,
                'categories' => ['Desktop', 'CPU'],
                'short_description' => 'Balanced Ryzen 9 desktop processor for premium gaming and creator builds.',
                'description' => '<p>Ryzen 9 9900X offers strong AM5 desktop performance for gaming, coding, and production work.</p>',
            ],
            [
                'sku' => 'CPU-AMD-Ryzen 7 9800X3D-AM5',
                'name' => 'CPU AMD Ryzen 7 9800X3D (Up 5.2 GHz, 8 Cores 16 Threads, AM5)',
                'brand' => 'AMD',
                'price' => 13990000,
                'special_price' => 10990000,
                'qty' => 20,
                'categories' => ['Desktop', 'CPU'],
                'short_description' => 'High-value X3D gaming processor for AM5 enthusiasts.',
                'description' => '<p>Ryzen 7 9800X3D focuses on fast gaming performance with efficient thermals and a strong AM5 upgrade path.</p>',
            ],
            [
                'sku' => 'GPU-ASUS-TUF-RTX5080-OC',
                'name' => 'Card ASUS TUF Gaming GeForce RTX 5080 OC 16GB GDDR7',
                'brand' => 'ASUS',
                'price' => 54990000,
                'special_price' => 45990000,
                'qty' => 8,
                'categories' => ['Desktop', 'GPU'],
                'short_description' => 'Premium ASUS graphics card built for ultra settings and 4K play.',
                'description' => '<p>ASUS TUF RTX 5080 OC combines flagship cooling and next-gen GeForce performance for premium desktop systems.</p>',
            ],
            [
                'sku' => 'GPU-ASUS-PRIME-RTX5070TI-OC',
                'name' => 'Card ASUS Prime GeForce RTX 5070 Ti OC 16GB GDDR7',
                'brand' => 'ASUS',
                'price' => 32990000,
                'special_price' => 24990000,
                'qty' => 12,
                'categories' => ['Desktop', 'GPU'],
                'short_description' => 'High-end GeForce card for fast 1440p and entry 4K desktop builds.',
                'description' => '<p>ASUS Prime RTX 5070 Ti OC delivers strong ray tracing and AI throughput in a clean triple-fan design.</p>',
            ],
            [
                'sku' => 'MB-GIGABYTE-X870E-AORUS-MASTER',
                'name' => 'Mainboard Gigabyte X870E AORUS MASTER',
                'brand' => 'Gigabyte',
                'price' => 16990000,
                'special_price' => 13990000,
                'qty' => 14,
                'categories' => ['Desktop', 'Mainboard'],
                'short_description' => 'Flagship AMD AM5 motherboard with strong VRM and premium connectivity.',
                'description' => '<p>X870E AORUS MASTER is built for Ryzen flagship systems with robust power delivery and top-tier expansion.</p>',
            ],
            [
                'sku' => 'MB-GIGABYTE-B650E-AORUS-ELITE-AX',
                'name' => 'Mainboard Gigabyte B650E AORUS ELITE AX ICE',
                'brand' => 'Gigabyte',
                'price' => 7990000,
                'special_price' => 6190000,
                'qty' => 22,
                'categories' => ['Desktop', 'Mainboard'],
                'short_description' => 'Value-focused AM5 board with Wi-Fi and PCIe Gen5 support.',
                'description' => '<p>B650E AORUS ELITE AX ICE brings strong daily AM5 performance and expansion for modern desktop builds.</p>',
            ],
            [
                'sku' => 'RAM-CORSAIR-DOMINATOR-64-DDR5-6400',
                'name' => 'RAM Corsair Dominator Titanium RGB 64GB DDR5 6400',
                'brand' => 'Corsair',
                'price' => 8590000,
                'special_price' => 7290000,
                'qty' => 26,
                'categories' => ['Desktop', 'RAM'],
                'short_description' => 'Premium DDR5 kit for flagship gaming and creator systems.',
                'description' => '<p>Corsair Dominator Titanium RGB 64GB pairs premium thermals with fast DDR5 speed for modern high-end PCs.</p>',
            ],
            [
                'sku' => 'RAM-CORSAIR-VENGEANCE-32-DDR5-6000',
                'name' => 'RAM Corsair Vengeance RGB 32GB DDR5 6000',
                'brand' => 'Corsair',
                'price' => 3490000,
                'special_price' => 2590000,
                'qty' => 34,
                'categories' => ['Desktop', 'RAM'],
                'short_description' => 'Fast DDR5 memory kit for balanced performance desktops.',
                'description' => '<p>Corsair Vengeance RGB 32GB DDR5 6000 is an ideal memory choice for gaming and productivity PCs.</p>',
            ],
            [
                'sku' => 'RAM-CORSAIR-VENGEANCE-64-DDR5-6400',
                'name' => 'RAM Corsair Vengeance RGB 64GB DDR5 6400',
                'brand' => 'Corsair',
                'price' => 5890000,
                'special_price' => 4990000,
                'qty' => 28,
                'categories' => ['Desktop', 'RAM'],
                'short_description' => 'High-capacity DDR5 kit for demanding multitasking and creator work.',
                'description' => '<p>Vengeance RGB 64GB DDR5 6400 delivers higher memory capacity for editing, coding, and heavy multitasking.</p>',
            ],
            [
                'sku' => 'SSD-SEAGATE-FIRECUDA-530R-2TB',
                'name' => 'SSD Seagate FireCuda 530R 2TB PCIe Gen4 NVMe',
                'brand' => 'Seagate',
                'price' => 5190000,
                'special_price' => 3990000,
                'qty' => 30,
                'categories' => ['Desktop', 'SSD'],
                'short_description' => 'High-speed NVMe SSD for games, workstations, and creator files.',
                'description' => '<p>FireCuda 530R 2TB offers fast Gen4 storage for game libraries, scratch disks, and daily desktop speed.</p>',
            ],
            [
                'sku' => 'SSD-SEAGATE-FIRECUDA-530R-4TB',
                'name' => 'SSD Seagate FireCuda 530R 4TB PCIe Gen4 NVMe',
                'brand' => 'Seagate',
                'price' => 9990000,
                'special_price' => 8490000,
                'qty' => 18,
                'categories' => ['Desktop', 'SSD'],
                'short_description' => 'Large-capacity Gen4 SSD for premium gaming and creator builds.',
                'description' => '<p>FireCuda 530R 4TB gives desktop users fast storage and large capacity in a single NVMe drive.</p>',
            ],
            [
                'sku' => 'SSD-SEAGATE-FIRECUDA-540-1TB',
                'name' => 'SSD Seagate FireCuda 540 1TB PCIe Gen5 NVMe',
                'brand' => 'Seagate',
                'price' => 2890000,
                'special_price' => 2290000,
                'qty' => 32,
                'categories' => ['Desktop', 'SSD'],
                'short_description' => 'Entry Gen5 storage for ultra-fast desktop boot and load times.',
                'description' => '<p>FireCuda 540 1TB brings PCIe Gen5 responsiveness to enthusiast desktops that need top-tier storage speed.</p>',
            ],
            [
                'sku' => 'HDD-SEAGATE-IRONWOLF-8TB',
                'name' => 'HDD Seagate IronWolf 8TB 7200RPM NAS Drive',
                'brand' => 'Seagate',
                'price' => 5390000,
                'special_price' => 4490000,
                'qty' => 22,
                'categories' => ['Desktop', 'HDD'],
                'short_description' => 'Reliable high-capacity hard drive for NAS and archival desktops.',
                'description' => '<p>IronWolf 8TB provides dependable high-capacity storage for desktop backups, media, and project archives.</p>',
            ],
            [
                'sku' => 'HDD-SEAGATE-IRONWOLFPRO-12TB',
                'name' => 'HDD Seagate IronWolf Pro 12TB 7200RPM NAS Drive',
                'brand' => 'Seagate',
                'price' => 8790000,
                'special_price' => 6790000,
                'qty' => 16,
                'categories' => ['Desktop', 'HDD'],
                'short_description' => 'Professional NAS drive for larger creator and workstation storage arrays.',
                'description' => '<p>IronWolf Pro 12TB is tuned for heavier workloads and larger always-on storage deployments.</p>',
            ],
            [
                'sku' => 'HDD-SEAGATE-EXOS-X20-20TB',
                'name' => 'HDD Seagate Exos X20 20TB Enterprise Drive',
                'brand' => 'Seagate',
                'price' => 12990000,
                'special_price' => null,
                'qty' => 12,
                'categories' => ['Desktop', 'HDD'],
                'short_description' => 'Enterprise-grade hard drive for large storage pools and backups.',
                'description' => '<p>Exos X20 20TB offers large-capacity storage for servers, content archives, and workstation projects.</p>',
            ],
            [
                'sku' => 'PSU-CORSAIR-RM850X-SHIFT',
                'name' => 'PSU Corsair RM850x Shift 850W 80 Plus Gold',
                'brand' => 'Corsair',
                'price' => 4190000,
                'special_price' => 3290000,
                'qty' => 24,
                'categories' => ['Desktop', 'PSU'],
                'short_description' => 'Quiet fully modular PSU for premium gaming and creator desktops.',
                'description' => '<p>RM850x Shift offers clean cable routing and stable 80 Plus Gold power for modern PC builds.</p>',
            ],
            [
                'sku' => 'PSU-CORSAIR-RM1000X-SHIFT',
                'name' => 'PSU Corsair RM1000x Shift 1000W 80 Plus Gold',
                'brand' => 'Corsair',
                'price' => 5790000,
                'special_price' => 4690000,
                'qty' => 18,
                'categories' => ['Desktop', 'PSU'],
                'short_description' => '1000W modular PSU for high-power RTX builds and workstations.',
                'description' => '<p>RM1000x Shift is designed for modern flagship GPUs and clean premium desktop cable management.</p>',
            ],
            [
                'sku' => 'PSU-CORSAIR-HX1200I',
                'name' => 'PSU Corsair HX1200i 1200W 80 Plus Platinum',
                'brand' => 'Corsair',
                'price' => 8990000,
                'special_price' => 7490000,
                'qty' => 12,
                'categories' => ['Desktop', 'PSU'],
                'short_description' => 'High-end platinum PSU for enthusiast and workstation systems.',
                'description' => '<p>HX1200i brings platinum efficiency and stable power delivery to top-end desktop configurations.</p>',
            ],
            [
                'sku' => 'COOL-CORSAIR-TITAN-360-RX',
                'name' => 'CPU Cooler Corsair iCUE LINK TITAN 360 RX RGB',
                'brand' => 'Corsair',
                'price' => 7690000,
                'special_price' => 5990000,
                'qty' => 18,
                'categories' => ['Desktop', 'Heatsink'],
                'short_description' => 'Premium 360mm AIO cooler for flagship desktop CPUs.',
                'description' => '<p>iCUE LINK TITAN 360 RX RGB delivers premium thermals and cleaner cable routing for high-end builds.</p>',
            ],
            [
                'sku' => 'COOL-CORSAIR-H150I-LCD-XT',
                'name' => 'CPU Cooler Corsair iCUE H150i LCD XT 360mm',
                'brand' => 'Corsair',
                'price' => 6990000,
                'special_price' => 5790000,
                'qty' => 16,
                'categories' => ['Desktop', 'Heatsink'],
                'short_description' => 'LCD AIO cooler with premium cooling performance and visual customization.',
                'description' => '<p>H150i LCD XT combines strong 360mm cooling with a customizable display for showcase PCs.</p>',
            ],
            [
                'sku' => 'COOL-CORSAIR-H100I-ELITE',
                'name' => 'CPU Cooler Corsair iCUE H100i Elite 240mm',
                'brand' => 'Corsair',
                'price' => 4190000,
                'special_price' => 3190000,
                'qty' => 20,
                'categories' => ['Desktop', 'Heatsink'],
                'short_description' => 'Compact AIO cooler for balanced gaming and productivity builds.',
                'description' => '<p>H100i Elite offers dependable 240mm liquid cooling for mainstream enthusiast desktops.</p>',
            ],
            [
                'sku' => 'FAN-CORSAIR-QX120-TRIPLE',
                'name' => 'Case Fan Corsair iCUE LINK QX120 RGB Triple Pack',
                'brand' => 'Corsair',
                'price' => 4190000,
                'special_price' => 3490000,
                'qty' => 26,
                'categories' => ['Desktop', 'Fan'],
                'short_description' => 'Premium RGB fan pack for airflow-focused showcase builds.',
                'description' => '<p>QX120 RGB fans deliver strong airflow, lighting, and simplified cable management for modern desktops.</p>',
            ],
            [
                'sku' => 'FAN-CORSAIR-RX120-TRIPLE',
                'name' => 'Case Fan Corsair iCUE LINK RX120 RGB Triple Pack',
                'brand' => 'Corsair',
                'price' => 3290000,
                'special_price' => 2490000,
                'qty' => 24,
                'categories' => ['Desktop', 'Fan'],
                'short_description' => 'Balanced RGB fan kit for clean airflow and lower noise.',
                'description' => '<p>RX120 RGB triple pack is ideal for balanced airflow, lighting, and day-to-day cooling upgrades.</p>',
            ],
            [
                'sku' => 'FAN-CORSAIR-AF120RGB-TRIPLE',
                'name' => 'Case Fan Corsair AF120 RGB Elite Triple Pack',
                'brand' => 'Corsair',
                'price' => 1990000,
                'special_price' => 1490000,
                'qty' => 36,
                'categories' => ['Desktop', 'Fan'],
                'short_description' => 'Affordable RGB airflow kit for mainstream gaming systems.',
                'description' => '<p>AF120 RGB Elite gives builders an easy way to add airflow and clean lighting to new PC builds.</p>',
            ],
            [
                'sku' => 'CASE-CORSAIR-5000D-AIRFLOW',
                'name' => 'Case Corsair 5000D Airflow Tempered Glass',
                'brand' => 'Corsair',
                'price' => 4290000,
                'special_price' => 3490000,
                'qty' => 18,
                'categories' => ['Desktop', 'Case'],
                'short_description' => 'Premium mid-tower chassis focused on cooling and cable management.',
                'description' => '<p>Corsair 5000D Airflow is a versatile high-airflow case for powerful gaming and creator desktops.</p>',
            ],
            [
                'sku' => 'CASE-CORSAIR-3500X-ARGB',
                'name' => 'Case Corsair 3500X ARGB Tempered Glass',
                'brand' => 'Corsair',
                'price' => 3290000,
                'special_price' => 2490000,
                'qty' => 20,
                'categories' => ['Desktop', 'Case'],
                'short_description' => 'Showcase chassis with strong airflow and integrated ARGB presentation.',
                'description' => '<p>3500X ARGB delivers a clean tempered-glass layout for builders who want style and airflow.</p>',
            ],
            [
                'sku' => 'CASE-CORSAIR-6500X',
                'name' => 'Case Corsair 6500X Dual Chamber',
                'brand' => 'Corsair',
                'price' => 5490000,
                'special_price' => 4690000,
                'qty' => 12,
                'categories' => ['Desktop', 'Case'],
                'short_description' => 'Dual-chamber enthusiast case for large showcase PC builds.',
                'description' => '<p>Corsair 6500X offers a roomy dual-chamber layout for flagship systems and clean presentation.</p>',
            ],
            [
                'sku' => 'LAP-APPLE-MBA13-M3',
                'name' => 'Apple MacBook Air 13 M3 16GB 512GB',
                'brand' => 'Apple',
                'price' => 31990000,
                'special_price' => 26990000,
                'qty' => 10,
                'categories' => ['Laptop', 'Apple', 'MacBook', 'Ultrabook'],
                'short_description' => 'Portable Apple laptop for daily work, study, and travel.',
                'description' => '<p>MacBook Air 13 with M3 balances lightweight portability, long battery life, and fast everyday performance.</p>',
            ],
            [
                'sku' => 'LAP-DELL-LATITUDE-9450',
                'name' => 'Dell Latitude 9450 2-in-1 Core Ultra 7 32GB 1TB',
                'brand' => 'Dell',
                'price' => 46990000,
                'special_price' => 38990000,
                'qty' => 9,
                'categories' => ['Laptop', 'Business Laptop'],
                'short_description' => 'Premium business notebook with flexible 2-in-1 productivity.',
                'description' => '<p>Latitude 9450 focuses on business mobility, strong build quality, and efficient daily productivity.</p>',
            ],
            [
                'sku' => 'MON-SAMSUNG-ODYSSEY-NEOG9',
                'name' => 'Samsung Odyssey Neo G9 49 Dual UHD 240Hz',
                'brand' => 'Samsung',
                'price' => 46990000,
                'special_price' => 36990000,
                'qty' => 7,
                'categories' => ['Monitor', 'Gaming Monitor', 'Ultrawide Monitor'],
                'short_description' => 'Immersive super-ultrawide display for premium sim, racing, and multitasking setups.',
                'description' => '<p>Odyssey Neo G9 delivers an expansive 49-inch desktop view for immersive gaming and wide productivity layouts.</p>',
            ],
            [
                'sku' => 'MON-SAMSUNG-VIEWFINITY-S9',
                'name' => 'Samsung ViewFinity S9 27 5K Creator Monitor',
                'brand' => 'Samsung',
                'price' => 32990000,
                'special_price' => 27990000,
                'qty' => 9,
                'categories' => ['Monitor', 'Creator Monitor'],
                'short_description' => 'High-resolution creator monitor for photography, design, and studio workflows.',
                'description' => '<p>ViewFinity S9 offers crisp 5K detail and creator-oriented image quality for professional desktop work.</p>',
            ],
            [
                'sku' => 'DESK-APPLE-MAC-MINI-M4P',
                'name' => 'Apple Mac mini M4 Pro 24GB 512GB',
                'brand' => 'Apple',
                'price' => 41990000,
                'special_price' => 35990000,
                'qty' => 11,
                'categories' => ['Desktop', 'Apple', 'Mac Desktop'],
                'short_description' => 'Compact Apple desktop for development, office work, and creative tasks.',
                'description' => '<p>Mac mini M4 Pro packs strong Apple silicon performance into a compact desktop suited for professional workflows.</p>',
            ],
            [
                'sku' => 'APL-IPHONE-16-128',
                'name' => 'Apple iPhone 16 128GB Ultramarine',
                'brand' => 'Apple',
                'price' => 22990000,
                'special_price' => 18990000,
                'qty' => 14,
                'categories' => ['Apple', 'iPhone'],
                'short_description' => 'Mainstream iPhone with strong cameras and smooth daily performance.',
                'description' => '<p>iPhone 16 brings a balanced Apple mobile experience with excellent battery life and camera quality.</p>',
            ],
            [
                'sku' => 'APL-IPAD-AIR13-M2-128',
                'name' => 'Apple iPad Air 13 M2 128GB Wi-Fi',
                'brand' => 'Apple',
                'price' => 23990000,
                'special_price' => 19990000,
                'qty' => 13,
                'categories' => ['Apple', 'iPad'],
                'short_description' => 'Large-screen iPad Air for note-taking, drawing, and mobile productivity.',
                'description' => '<p>iPad Air 13 with M2 offers a spacious display and strong tablet performance for everyday Apple workflows.</p>',
            ],
            [
                'sku' => 'APL-AIRPODS-4-ANC',
                'name' => 'Apple AirPods 4 with Active Noise Cancellation',
                'brand' => 'Apple',
                'price' => 4990000,
                'special_price' => 3790000,
                'qty' => 25,
                'categories' => ['Apple', 'AirPods', 'Accessories'],
                'short_description' => 'Compact Apple earbuds with everyday ANC and seamless pairing.',
                'description' => '<p>AirPods 4 with ANC bring Apple ecosystem convenience and cleaner listening for work and commuting.</p>',
            ],
            [
                'sku' => 'APL-WATCH-ULTRA2-BLACK',
                'name' => 'Apple Watch Ultra 2 Black Titanium Ocean Band',
                'brand' => 'Apple',
                'price' => 20990000,
                'special_price' => 15990000,
                'qty' => 10,
                'categories' => ['Apple', 'Apple Watch'],
                'short_description' => 'Rugged premium Apple wearable for training, travel, and daily use.',
                'description' => '<p>Apple Watch Ultra 2 is designed for active users who want a larger display, stronger battery life, and durable titanium build.</p>',
            ],
            [
                'sku' => 'CPU-AMD-Ryzen 7 9700X-AM5',
                'name' => 'CPU AMD Ryzen 7 9700X (Up 5.5 GHz, 8 Cores 16 Threads, AM5)',
                'brand' => 'AMD',
                'price' => 9990000,
                'special_price' => 8990000,
                'qty' => 26,
                'categories' => ['Desktop', 'CPU'],
                'short_description' => 'Balanced AM5 processor for high-refresh gaming and fast daily desktop work.',
                'description' => '<p>Ryzen 7 9700X is a strong-value AM5 processor for gaming, coding, and multitasking desktops.</p>',
            ],
            [
                'sku' => 'COOL-NOCTUA-NH-D15-G2',
                'name' => 'CPU Cooler Noctua NH-D15 G2',
                'brand' => 'Noctua',
                'price' => 3890000,
                'special_price' => 3290000,
                'qty' => 20,
                'categories' => ['Desktop', 'Heatsink'],
                'short_description' => 'Premium dual-tower air cooler for flagship desktop CPUs.',
                'description' => '<p>Noctua NH-D15 G2 is a flagship air cooler built for strong thermal performance and low noise.</p>',
            ],
            [
                'sku' => 'COOL-DEEPCOOL-MYSTIQUE-360',
                'name' => 'CPU Cooler DeepCool Mystique 360 ARGB',
                'brand' => 'DeepCool',
                'price' => 4990000,
                'special_price' => 3990000,
                'qty' => 18,
                'categories' => ['Desktop', 'Heatsink'],
                'short_description' => '360mm liquid cooler with premium thermals and a modern ARGB presentation.',
                'description' => '<p>DeepCool Mystique 360 is designed for showpiece builds that need strong 360mm cooling performance.</p>',
            ],
            [
                'sku' => 'FAN-CORSAIR-LX120-TRIPLE',
                'name' => 'Case Fan Corsair iCUE LINK LX120 RGB Triple Pack',
                'brand' => 'Corsair',
                'price' => 3490000,
                'special_price' => 2990000,
                'qty' => 22,
                'categories' => ['Desktop', 'Fan'],
                'short_description' => 'Premium airflow fan kit with iCUE LINK integration and ARGB lighting.',
                'description' => '<p>Corsair LX120 RGB fans target premium PC builders who want strong airflow and cleaner cabling.</p>',
            ],
            [
                'sku' => 'FAN-CORSAIR-RS120-TRIPLE',
                'name' => 'Case Fan Corsair RS120 ARGB Triple Pack',
                'brand' => 'Corsair',
                'price' => 1990000,
                'special_price' => 1690000,
                'qty' => 26,
                'categories' => ['Desktop', 'Fan'],
                'short_description' => 'Value-focused ARGB fan trio for airflow upgrades and cleaner cases.',
                'description' => '<p>Corsair RS120 ARGB is a practical triple-fan kit for builders who want airflow and lighting at a lower cost.</p>',
            ],
            [
                'sku' => 'RAM-CORSAIR-DOMINATOR-32-DDR5-6200',
                'name' => 'RAM Corsair Dominator Titanium RGB 32GB DDR5 6200',
                'brand' => 'Corsair',
                'price' => 4490000,
                'special_price' => 3890000,
                'qty' => 24,
                'categories' => ['Desktop', 'RAM'],
                'short_description' => 'Premium DDR5 memory kit for elegant flagship gaming builds.',
                'description' => '<p>Dominator Titanium RGB 32GB brings premium build quality, RGB lighting, and fast DDR5 performance.</p>',
            ],
            [
                'sku' => 'SSD-SEAGATE-FIRECUDA-530R-1TB',
                'name' => 'SSD Seagate FireCuda 530R 1TB PCIe Gen4 NVMe',
                'brand' => 'Seagate',
                'price' => 2990000,
                'special_price' => 2490000,
                'qty' => 30,
                'categories' => ['Desktop', 'SSD'],
                'short_description' => 'Fast Gen4 gaming SSD for OS, software, and active project storage.',
                'description' => '<p>FireCuda 530R 1TB gives desktops responsive Gen4 storage performance in a practical capacity.</p>',
            ],
            [
                'sku' => 'SSD-SEAGATE-FIRECUDA-540-2TB',
                'name' => 'SSD Seagate FireCuda 540 2TB PCIe Gen5 NVMe',
                'brand' => 'Seagate',
                'price' => 4590000,
                'special_price' => 3790000,
                'qty' => 24,
                'categories' => ['Desktop', 'SSD'],
                'short_description' => 'High-speed Gen5 SSD for flagship enthusiast desktops.',
                'description' => '<p>FireCuda 540 2TB combines strong sequential throughput with generous capacity for fast desktop storage.</p>',
            ],
            [
                'sku' => 'HDD-SEAGATE-IRONWOLF-4TB',
                'name' => 'HDD Seagate IronWolf 4TB 5900RPM NAS Drive',
                'brand' => 'Seagate',
                'price' => 3490000,
                'special_price' => 2990000,
                'qty' => 26,
                'categories' => ['Desktop', 'HDD'],
                'short_description' => 'Reliable entry NAS hard drive for home servers, media, and backups.',
                'description' => '<p>IronWolf 4TB is a dependable storage drive for backups, NAS builds, and always-on use.</p>',
            ],
            [
                'sku' => 'HDD-SEAGATE-SKYHAWKAI-10TB',
                'name' => 'HDD Seagate SkyHawk AI 10TB Surveillance Drive',
                'brand' => 'Seagate',
                'price' => 5890000,
                'special_price' => 4990000,
                'qty' => 18,
                'categories' => ['Desktop', 'HDD'],
                'short_description' => 'Large-capacity HDD for surveillance and heavy-write archival workloads.',
                'description' => '<p>SkyHawk AI 10TB is tuned for sustained write workloads and large always-on storage environments.</p>',
            ],
            [
                'sku' => 'MB-ASUS-ROG-STRIX-X870E-E',
                'name' => 'Mainboard ASUS ROG Strix X870E-E Gaming WiFi',
                'brand' => 'ASUS',
                'price' => 14490000,
                'special_price' => 11990000,
                'qty' => 16,
                'categories' => ['Desktop', 'Mainboard'],
                'short_description' => 'Premium AM5 motherboard with strong power design and gamer-focused connectivity.',
                'description' => '<p>ROG Strix X870E-E is designed for premium AM5 systems with fast I/O, robust thermals, and stable power delivery.</p>',
            ],
            [
                'sku' => 'MB-MSI-MPG-X870E-CARBON',
                'name' => 'Mainboard MSI MPG X870E CARBON WIFI',
                'brand' => 'MSI',
                'price' => 11490000,
                'special_price' => 9390000,
                'qty' => 18,
                'categories' => ['Desktop', 'Mainboard'],
                'short_description' => 'High-end AM5 motherboard balancing performance, thermals, and premium connectivity.',
                'description' => '<p>MSI MPG X870E CARBON WIFI is a performance-focused board for enthusiast Ryzen desktop platforms.</p>',
            ],
            [
                'sku' => 'PSU-CORSAIR-HX1500I',
                'name' => 'PSU Corsair HX1500i 1500W 80 Plus Platinum',
                'brand' => 'Corsair',
                'price' => 12990000,
                'special_price' => 10990000,
                'qty' => 10,
                'categories' => ['Desktop', 'PSU'],
                'short_description' => 'Extreme-capacity platinum PSU for flagship multi-device desktops and workstations.',
                'description' => '<p>HX1500i targets ultra-high-end systems that need premium efficiency and stable power headroom.</p>',
            ],
            [
                'sku' => 'PSU-CORSAIR-SF1000L',
                'name' => 'PSU Corsair SF1000L 1000W 80 Plus Gold SFX-L',
                'brand' => 'Corsair',
                'price' => 5490000,
                'special_price' => 4690000,
                'qty' => 16,
                'categories' => ['Desktop', 'PSU'],
                'short_description' => 'Compact high-power PSU for premium small-form-factor builds.',
                'description' => '<p>Corsair SF1000L brings strong wattage and modern cable standards to compact enthusiast PCs.</p>',
            ],
            [
                'sku' => 'LAP-APPLE-MBA13-M2',
                'name' => 'Apple MacBook Air 13 M2 16GB 512GB',
                'brand' => 'Apple',
                'price' => 27990000,
                'special_price' => 23990000,
                'qty' => 12,
                'categories' => ['Laptop', 'Apple', 'MacBook', 'Ultrabook'],
                'short_description' => 'Slim Apple notebook for daily work, study, and long battery life.',
                'description' => '<p>MacBook Air 13 with M2 is a light, silent, and reliable notebook for everyday Apple workflows.</p>',
            ],
            [
                'sku' => 'LAP-APPLE-MBP16-M4MAX',
                'name' => 'Apple MacBook Pro 16 M4 Max 48GB 1TB',
                'brand' => 'Apple',
                'price' => 89990000,
                'special_price' => 79990000,
                'qty' => 7,
                'categories' => ['Laptop', 'Apple', 'MacBook', 'Creator Laptop'],
                'short_description' => 'High-end Apple notebook for studio, code, and advanced creator workloads.',
                'description' => '<p>MacBook Pro 16 M4 Max is built for professional teams that need strong sustained performance on the go.</p>',
            ],
            [
                'sku' => 'DESK-APPLE-IMAC24-M4',
                'name' => 'Apple iMac 24 M4 16GB 512GB',
                'brand' => 'Apple',
                'price' => 41990000,
                'special_price' => 36990000,
                'qty' => 10,
                'categories' => ['Desktop', 'Apple', 'Mac Desktop'],
                'short_description' => 'All-in-one Apple desktop with clean design and efficient daily performance.',
                'description' => '<p>iMac 24 M4 blends Apple silicon performance, a vibrant display, and a compact all-in-one footprint.</p>',
            ],
            [
                'sku' => 'DESK-APPLE-MAC-MINI-M4',
                'name' => 'Apple Mac mini M4 16GB 512GB',
                'brand' => 'Apple',
                'price' => 26990000,
                'special_price' => 21990000,
                'qty' => 12,
                'categories' => ['Desktop', 'Apple', 'Mac Desktop'],
                'short_description' => 'Compact Apple desktop for developers and modern office setups.',
                'description' => '<p>Mac mini M4 offers quiet and efficient Apple desktop performance in a very small form factor.</p>',
            ],
            [
                'sku' => 'DESK-APPLE-MAC-STUDIO-M4MAX',
                'name' => 'Apple Mac Studio M4 Max 64GB 1TB',
                'brand' => 'Apple',
                'price' => 89990000,
                'special_price' => 79990000,
                'qty' => 8,
                'categories' => ['Desktop', 'Apple', 'Mac Desktop'],
                'short_description' => 'Professional Apple desktop built for graphics, code, and studio workloads.',
                'description' => '<p>Mac Studio M4 Max delivers workstation-class Apple silicon performance for demanding production teams.</p>',
            ],
            [
                'sku' => 'APL-IPHONE-16PRO-128',
                'name' => 'Apple iPhone 16 Pro 128GB Desert Titanium',
                'brand' => 'Apple',
                'price' => 30990000,
                'special_price' => 26990000,
                'qty' => 12,
                'categories' => ['Apple', 'iPhone'],
                'short_description' => 'Premium compact iPhone with pro cameras and titanium design.',
                'description' => '<p>iPhone 16 Pro delivers flagship Apple performance and imaging in a more compact premium format.</p>',
            ],
            [
                'sku' => 'APL-IPHONE-16PLUS-128',
                'name' => 'Apple iPhone 16 Plus 128GB Pink',
                'brand' => 'Apple',
                'price' => 24990000,
                'special_price' => 21990000,
                'qty' => 14,
                'categories' => ['Apple', 'iPhone'],
                'short_description' => 'Large-screen iPhone for battery life and daily multimedia use.',
                'description' => '<p>iPhone 16 Plus pairs a large display and longer battery life with Apple’s latest mainstream experience.</p>',
            ],
            [
                'sku' => 'APL-IPHONE-15-128',
                'name' => 'Apple iPhone 15 128GB Blue',
                'brand' => 'Apple',
                'price' => 18990000,
                'special_price' => 15990000,
                'qty' => 16,
                'categories' => ['Apple', 'iPhone'],
                'short_description' => 'Balanced iPhone option with dynamic island and strong camera quality.',
                'description' => '<p>iPhone 15 remains a strong value Apple smartphone for daily use, photos, and ecosystem integration.</p>',
            ],
            [
                'sku' => 'APL-IPAD-AIR11-M2-128',
                'name' => 'Apple iPad Air 11 M2 128GB Wi-Fi',
                'brand' => 'Apple',
                'price' => 17990000,
                'special_price' => 15990000,
                'qty' => 16,
                'categories' => ['Apple', 'iPad'],
                'short_description' => 'Portable Apple tablet for notes, streaming, and creative work on the move.',
                'description' => '<p>iPad Air 11 with M2 offers strong Apple tablet performance in a more portable everyday size.</p>',
            ],
            [
                'sku' => 'APL-IPAD-MINI-A17PRO-128',
                'name' => 'Apple iPad mini A17 Pro 128GB Wi-Fi',
                'brand' => 'Apple',
                'price' => 13990000,
                'special_price' => 11990000,
                'qty' => 18,
                'categories' => ['Apple', 'iPad'],
                'short_description' => 'Compact iPad for travel, notes, sketching, and one-hand reading.',
                'description' => '<p>iPad mini A17 Pro focuses on portability while keeping Apple tablet speed and app quality.</p>',
            ],
            [
                'sku' => 'APL-IPAD-11-A16-128',
                'name' => 'Apple iPad 11 A16 128GB Wi-Fi',
                'brand' => 'Apple',
                'price' => 11990000,
                'special_price' => 9990000,
                'qty' => 20,
                'categories' => ['Apple', 'iPad'],
                'short_description' => 'Mainstream iPad for school, work, and everyday Apple tablet use.',
                'description' => '<p>Apple iPad 11 offers a balanced tablet experience for study, family use, and entertainment.</p>',
            ],
            [
                'sku' => 'APL-AIRPODS-4',
                'name' => 'Apple AirPods 4 Open-Ear USB-C',
                'brand' => 'Apple',
                'price' => 3490000,
                'special_price' => 2790000,
                'qty' => 24,
                'categories' => ['Apple', 'AirPods', 'Accessories'],
                'short_description' => 'Lightweight open-ear AirPods for daily calls, music, and commuting.',
                'description' => '<p>AirPods 4 provide a comfortable open-ear Apple listening experience with easy device switching.</p>',
            ],
            [
                'sku' => 'APL-AIRPODSMAX-USBC',
                'name' => 'Apple AirPods Max USB-C Midnight',
                'brand' => 'Apple',
                'price' => 14990000,
                'special_price' => 11990000,
                'qty' => 12,
                'categories' => ['Apple', 'AirPods', 'Accessories'],
                'short_description' => 'Premium Apple over-ear headphones with spatial audio and ANC.',
                'description' => '<p>AirPods Max USB-C deliver Apple’s premium over-ear listening experience with refined sound and comfort.</p>',
            ],
            [
                'sku' => 'APL-AIRPODS-3-LIGHTNING',
                'name' => 'Apple AirPods 3 Lightning Charging Case',
                'brand' => 'Apple',
                'price' => 3190000,
                'special_price' => 2490000,
                'qty' => 16,
                'categories' => ['Apple', 'AirPods', 'Accessories'],
                'short_description' => 'Comfortable semi-in-ear Apple earbuds with spatial audio support.',
                'description' => '<p>AirPods 3 remain a practical Apple audio option for users who prefer an open in-ear fit.</p>',
            ],
            [
                'sku' => 'APL-WATCH-S10-42',
                'name' => 'Apple Watch Series 10 42mm GPS Aluminum',
                'brand' => 'Apple',
                'price' => 9990000,
                'special_price' => 7990000,
                'qty' => 18,
                'categories' => ['Apple', 'Apple Watch'],
                'short_description' => 'Compact Apple smartwatch with health tracking and bright daily usability.',
                'description' => '<p>Apple Watch Series 10 42mm offers the latest Apple wearable experience in a smaller format.</p>',
            ],
            [
                'sku' => 'APL-WATCH-SE2-44',
                'name' => 'Apple Watch SE 2nd Gen 44mm GPS',
                'brand' => 'Apple',
                'price' => 7490000,
                'special_price' => 5990000,
                'qty' => 20,
                'categories' => ['Apple', 'Apple Watch'],
                'short_description' => 'Accessible Apple smartwatch for notifications, fitness, and family use.',
                'description' => '<p>Apple Watch SE 2nd Gen is a value-focused wearable for daily health and Apple ecosystem basics.</p>',
            ],
            [
                'sku' => 'APL-WATCH-ULTRA2-NATURAL',
                'name' => 'Apple Watch Ultra 2 Natural Titanium Alpine Loop',
                'brand' => 'Apple',
                'price' => 20990000,
                'special_price' => 16990000,
                'qty' => 10,
                'categories' => ['Apple', 'Apple Watch'],
                'short_description' => 'High-end rugged Apple wearable for training, hiking, and endurance use.',
                'description' => '<p>Apple Watch Ultra 2 Natural Titanium is designed for users who want durability, battery life, and outdoor features.</p>',
            ],
            [
                'sku' => 'LAP-MSI-RAIDER18HX-4090',
                'name' => 'MSI Raider 18 HX AI Core Ultra 9 RTX 4090',
                'brand' => 'MSI',
                'price' => 83990000,
                'special_price' => 69990000,
                'qty' => 6,
                'categories' => ['Laptop', 'Gaming Laptop'],
                'short_description' => 'Extreme gaming laptop with flagship RTX power and large-format cooling.',
                'description' => '<p>MSI Raider 18 HX AI is built for top-end gaming, streaming, and desktop-class mobile performance.</p>',
            ],
            [
                'sku' => 'LAP-ACER-PREDATOR-HELIOS16-4080',
                'name' => 'Acer Predator Helios 16 Core i9 RTX 4080',
                'brand' => 'Acer',
                'price' => 61990000,
                'special_price' => 49990000,
                'qty' => 8,
                'categories' => ['Laptop', 'Gaming Laptop'],
                'short_description' => 'High-performance gaming laptop with strong cooling and bright high-refresh visuals.',
                'description' => '<p>Predator Helios 16 targets gamers and creators who want strong RTX performance in a premium 16-inch chassis.</p>',
            ],
            [
                'sku' => 'LAP-LENOVO-THINKPAD-X1CARBON-G12',
                'name' => 'Lenovo ThinkPad X1 Carbon Gen 12 Core Ultra 7 32GB 1TB',
                'brand' => 'Lenovo',
                'price' => 49990000,
                'special_price' => 42990000,
                'qty' => 10,
                'categories' => ['Laptop', 'Business Laptop', 'Ultrabook'],
                'short_description' => 'Premium business ultrabook with lightweight carbon design and enterprise reliability.',
                'description' => '<p>ThinkPad X1 Carbon Gen 12 is a high-end business notebook focused on mobility, typing comfort, and build quality.</p>',
            ],
            [
                'sku' => 'LAP-HP-ELITEBOOK-ULTRA-G1Q',
                'name' => 'HP EliteBook Ultra G1q Snapdragon X Elite 32GB 1TB',
                'brand' => 'HP',
                'price' => 45990000,
                'special_price' => 39990000,
                'qty' => 10,
                'categories' => ['Laptop', 'Business Laptop', 'Ultrabook'],
                'short_description' => 'Premium AI-ready business ultrabook with long battery life and light chassis.',
                'description' => '<p>EliteBook Ultra G1q focuses on mobility, quiet operation, and modern business productivity.</p>',
            ],
            [
                'sku' => 'LAP-DELL-LATITUDE-7350',
                'name' => 'Dell Latitude 7350 Ultralight Core Ultra 7 16GB 512GB',
                'brand' => 'Dell',
                'price' => 32990000,
                'special_price' => 27990000,
                'qty' => 12,
                'categories' => ['Laptop', 'Business Laptop', 'Ultrabook'],
                'short_description' => 'Portable business laptop designed for travel, meetings, and long workdays.',
                'description' => '<p>Latitude 7350 Ultralight prioritizes portability, battery life, and dependable professional performance.</p>',
            ],
            [
                'sku' => 'LAP-ASUS-EXPERTBOOK-P5',
                'name' => 'ASUS ExpertBook P5 Core Ultra 7 32GB 1TB',
                'brand' => 'ASUS',
                'price' => 28990000,
                'special_price' => 23990000,
                'qty' => 12,
                'categories' => ['Laptop', 'Business Laptop', 'Ultrabook'],
                'short_description' => 'Clean and lightweight business notebook for office, travel, and hybrid work.',
                'description' => '<p>ASUS ExpertBook P5 delivers practical business features, portability, and modern Intel performance.</p>',
            ],
            [
                'sku' => 'MON-DELL-AW3423DWF',
                'name' => 'Dell Alienware AW3423DWF 34 QD-OLED 165Hz',
                'brand' => 'Dell',
                'price' => 19990000,
                'special_price' => 15990000,
                'qty' => 10,
                'categories' => ['Monitor', 'Gaming Monitor', 'Ultrawide Monitor'],
                'short_description' => 'Ultrawide QD-OLED gaming display with fast response and deep contrast.',
                'description' => '<p>Alienware AW3423DWF is a popular premium ultrawide monitor for immersive gaming and entertainment.</p>',
            ],
            [
                'sku' => 'MON-MSI-MPG-341CQPX',
                'name' => 'MSI MPG 341CQPX QD-OLED 34 175Hz',
                'brand' => 'MSI',
                'price' => 21990000,
                'special_price' => 17990000,
                'qty' => 10,
                'categories' => ['Monitor', 'Gaming Monitor', 'Ultrawide Monitor'],
                'short_description' => 'Premium MSI ultrawide QD-OLED display for fast and cinematic gameplay.',
                'description' => '<p>MSI MPG 341CQPX brings ultrawide QD-OLED image quality and high refresh gaming performance.</p>',
            ],
            [
                'sku' => 'MON-LG-45GS95QE',
                'name' => 'LG UltraGear 45GS95QE 45 OLED 240Hz',
                'brand' => 'LG',
                'price' => 41990000,
                'special_price' => 34990000,
                'qty' => 7,
                'categories' => ['Monitor', 'Gaming Monitor', 'Ultrawide Monitor'],
                'short_description' => 'Large curved OLED gaming display for immersive sim and action setups.',
                'description' => '<p>LG UltraGear 45GS95QE gives gamers a huge curved OLED canvas with fast 240Hz responsiveness.</p>',
            ],
            [
                'sku' => 'MON-ASUS-PG34WCDM',
                'name' => 'ASUS ROG Swift PG34WCDM 34 OLED 240Hz',
                'brand' => 'ASUS',
                'price' => 26990000,
                'special_price' => 21990000,
                'qty' => 8,
                'categories' => ['Monitor', 'Gaming Monitor', 'Ultrawide Monitor'],
                'short_description' => 'Fast OLED ultrawide monitor with premium motion clarity and gamer-focused tuning.',
                'description' => '<p>ROG Swift PG34WCDM is designed for enthusiast users who want OLED speed and an ultrawide format.</p>',
            ],
            [
                'sku' => 'MON-DELL-U3225QE',
                'name' => 'Dell UltraSharp U3225QE 32 4K IPS Black',
                'brand' => 'Dell',
                'price' => 21990000,
                'special_price' => 17990000,
                'qty' => 10,
                'categories' => ['Monitor', 'Creator Monitor', '4K Monitor'],
                'short_description' => 'Professional 32-inch 4K monitor for office, design, and studio workflows.',
                'description' => '<p>Dell UltraSharp U3225QE delivers premium connectivity, 4K sharpness, and dependable professional color.</p>',
            ],
            [
                'sku' => 'MON-BENQ-PD3225U',
                'name' => 'BenQ PD3225U 32 4K Designer Monitor',
                'brand' => 'BenQ',
                'price' => 28990000,
                'special_price' => 23990000,
                'qty' => 8,
                'categories' => ['Monitor', 'Creator Monitor', '4K Monitor'],
                'short_description' => 'Designer-focused 4K monitor for illustration, photo, and post-production work.',
                'description' => '<p>BenQ PD3225U focuses on accurate color reproduction and modern creator workflows on a 32-inch 4K panel.</p>',
            ],
            [
                'sku' => 'MON-APPLE-STUDIO-DISPLAY',
                'name' => 'Apple Studio Display 27 5K Standard Glass',
                'brand' => 'Apple',
                'price' => 42990000,
                'special_price' => 38990000,
                'qty' => 8,
                'categories' => ['Monitor', 'Creator Monitor'],
                'short_description' => 'Premium Apple 5K monitor for Mac-based creator and studio setups.',
                'description' => '<p>Apple Studio Display offers a refined 5K desktop experience with strong color, speakers, and webcam integration.</p>',
            ],
            [
                'sku' => 'LAP-ASUS-STRIX-SCAR16-4080',
                'name' => 'ASUS ROG Strix SCAR 16 Core i9 RTX 4080 Nebula HDR',
                'brand' => 'ASUS',
                'price' => 76990000,
                'special_price' => 69990000,
                'qty' => 8,
                'categories' => ['Laptop', 'Gaming Laptop'],
                'short_description' => 'Flagship 16-inch gaming laptop for competitive and enthusiast setups.',
                'description' => '<p>ROG Strix SCAR 16 combines high-refresh visuals, strong cooling, and RTX-class gaming performance for demanding users.</p>',
            ],
            [
                'sku' => 'LAP-APPLE-MBP14-M4',
                'name' => 'Apple MacBook Pro 14 M4 16GB 512GB',
                'brand' => 'Apple',
                'price' => 44990000,
                'special_price' => 41990000,
                'qty' => 10,
                'categories' => ['Laptop', 'Apple', 'MacBook', 'Creator Laptop'],
                'short_description' => 'Balanced MacBook Pro for creators who want strong performance in a compact size.',
                'description' => '<p>MacBook Pro 14 M4 delivers strong Apple silicon performance for photo, video, and software workflows in a travel-friendly design.</p>',
            ],
            [
                'sku' => 'LAP-APPLE-MBP16-M4PRO',
                'name' => 'Apple MacBook Pro 16 M4 Pro 36GB 1TB',
                'brand' => 'Apple',
                'price' => 76990000,
                'special_price' => 72990000,
                'qty' => 7,
                'categories' => ['Laptop', 'Apple', 'MacBook', 'Creator Laptop'],
                'short_description' => 'Large-format MacBook Pro for heavy creator and development workloads.',
                'description' => '<p>MacBook Pro 16 M4 Pro is built for users who want a large display, strong battery life, and sustained workstation-class Apple performance.</p>',
            ],
            [
                'sku' => 'CPU-AMD-Ryzen 5 9600X-AM5',
                'name' => 'CPU AMD Ryzen 5 9600X (Up 5.4 GHz, 6 Cores 12 Threads, AM5)',
                'brand' => 'AMD',
                'price' => 7890000,
                'special_price' => 6590000,
                'qty' => 24,
                'categories' => ['Desktop', 'CPU'],
                'short_description' => 'Mainstream AM5 processor for gaming-first desktop builds.',
                'description' => '<p>Ryzen 5 9600X offers efficient modern desktop performance for esports, school, and balanced daily PC builds.</p>',
            ],
            [
                'sku' => 'RAM-CORSAIR-VENGEANCE-96-DDR5-6000',
                'name' => 'RAM Corsair Vengeance RGB 96GB DDR5 6000',
                'brand' => 'Corsair',
                'price' => 10990000,
                'special_price' => 9290000,
                'qty' => 18,
                'categories' => ['Desktop', 'RAM'],
                'short_description' => 'Large-capacity DDR5 kit for creators, VMs, and heavy multitasking.',
                'description' => '<p>Corsair Vengeance RGB 96GB is designed for demanding workstation and creator desktops that need both capacity and speed.</p>',
            ],
            [
                'sku' => 'GPU-ASUS-TUF-RTX5070-OC',
                'name' => 'Card ASUS TUF Gaming GeForce RTX 5070 OC 12GB GDDR7',
                'brand' => 'ASUS',
                'price' => 26990000,
                'special_price' => 21990000,
                'qty' => 14,
                'categories' => ['Desktop', 'GPU'],
                'short_description' => 'Reliable TUF-series GPU for high-refresh 1440p gaming builds.',
                'description' => '<p>ASUS TUF RTX 5070 OC balances thermals and performance for modern 1440p gaming and creator systems.</p>',
            ],
            [
                'sku' => 'GPU-ASUS-TUF-RTX5060TI-OC',
                'name' => 'Card ASUS TUF Gaming GeForce RTX 5060 Ti OC 16GB GDDR7',
                'brand' => 'ASUS',
                'price' => 19990000,
                'special_price' => 16990000,
                'qty' => 16,
                'categories' => ['Desktop', 'GPU'],
                'short_description' => 'Upper-midrange GeForce option for efficient high-detail gaming builds.',
                'description' => '<p>ASUS TUF RTX 5060 Ti OC is aimed at users who want strong raster and AI performance in a dependable cooling design.</p>',
            ],
            [
                'sku' => 'CASE-CORSAIR-4000D-AIRFLOW',
                'name' => 'Case Corsair 4000D Airflow Tempered Glass',
                'brand' => 'Corsair',
                'price' => 2790000,
                'special_price' => 2390000,
                'qty' => 20,
                'categories' => ['Desktop', 'Case'],
                'short_description' => 'Compact airflow-focused mid tower for modern gaming and productivity builds.',
                'description' => '<p>Corsair 4000D Airflow remains a practical builder favorite thanks to clean design, easy cable routing, and strong cooling support.</p>',
            ],
            [
                'sku' => 'CASE-CORSAIR-7000D-AIRFLOW',
                'name' => 'Case Corsair 7000D Airflow Full Tower',
                'brand' => 'Corsair',
                'price' => 6390000,
                'special_price' => 5490000,
                'qty' => 10,
                'categories' => ['Desktop', 'Case'],
                'short_description' => 'Large full tower enclosure for showcase and water-cooled enthusiast systems.',
                'description' => '<p>Corsair 7000D Airflow provides generous interior space, strong airflow, and flexible mounting for high-end desktop builds.</p>',
            ],
        ];
    }
}
