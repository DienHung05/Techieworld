<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;

class SeedTechProducts implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {}

    public static function getDependencies(): array { return []; }
    public function getAliases(): array { return []; }

    public function apply(): static
    {
        $om = ObjectManager::getInstance();

        
        $eavSetupFactory = $om->get(EavSetupFactory::class);
        $eavSetup = $eavSetupFactory->create(['setup' => $this->setup]);
        if (!$eavSetup->getAttributeId(\Magento\Catalog\Model\Product::ENTITY, 'brand')) {
            $eavSetup->addAttribute(\Magento\Catalog\Model\Product::ENTITY, 'brand', [
                'type'                  => 'varchar',
                'label'                 => 'Brand',
                'input'                 => 'text',
                'required'              => false,
                'visible'               => true,
                'user_defined'          => true,
                'searchable'            => true,
                'filterable'            => false,
                'comparable'            => false,
                'visible_on_front'      => true,
                'used_in_product_listing' => true,
                'global'               => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'                => 'General',
            ]);
        }

        
        $categoryFactory = $om->get(\Magento\Catalog\Model\CategoryFactory::class);
        $storeManager    = $om->get(StoreManagerInterface::class);
        $rootId = (int) $storeManager->getStore()->getRootCategoryId();

        $categoryNames = ['GPU', 'CPU', 'RAM', 'SSD', 'Monitor', 'Laptop', 'Mainboard', 'PSU', 'Cooler', 'Accessories'];
        $categoryIds   = [];

        foreach ($categoryNames as $catName) {
            $collection = $om->get(\Magento\Catalog\Model\ResourceModel\Category\CollectionFactory::class)->create();
            $collection->addAttributeToFilter('name', $catName)->addAttributeToFilter('parent_id', $rootId)->setPageSize(1);
            if ($collection->getSize() > 0) {
                $categoryIds[$catName] = (int) $collection->getFirstItem()->getId();
            } else {
                $cat = $categoryFactory->create();
                $cat->setName($catName)->setParentId($rootId)->setIsActive(true)->setIncludeInMenu(true);
                $cat->save();
                $categoryIds[$catName] = (int) $cat->getId();
            }
        }

        
        $products = [
            
            ['sku'=>'GPU-001','name'=>'NVIDIA GeForce RTX 4090 24GB GDDR6X',          'brand'=>'NVIDIA',   'cat'=>'GPU',        'price'=>49900000,'special'=>36900000,'qty'=>8],
            ['sku'=>'GPU-002','name'=>'ASUS ROG Strix RTX 4080 Super 16GB OC',        'brand'=>'ASUS',     'cat'=>'GPU',        'price'=>39500000,'special'=>29900000,'qty'=>5],
            ['sku'=>'CPU-001','name'=>'Intel Core i9-14900K 24-Core 6.0GHz',          'brand'=>'Intel',    'cat'=>'CPU',        'price'=>16990000,'special'=>12490000,'qty'=>12],
            ['sku'=>'CPU-002','name'=>'AMD Ryzen 9 7950X 16-Core AM5 Desktop',        'brand'=>'AMD',      'cat'=>'CPU',        'price'=>16490000,'special'=>12990000,'qty'=>10],
            ['sku'=>'RAM-001','name'=>'Corsair Dominator Platinum 64GB DDR5-6000',    'brand'=>'Corsair',  'cat'=>'RAM',        'price'=>9990000, 'special'=>7490000, 'qty'=>15],
            ['sku'=>'RAM-002','name'=>'G.Skill Trident Z5 RGB 32GB DDR5-7200',        'brand'=>'G.Skill',  'cat'=>'RAM',        'price'=>6490000, 'special'=>4890000, 'qty'=>20],
            ['sku'=>'MON-001','name'=>'LG UltraGear 27GP950 4K 144Hz Nano IPS',       'brand'=>'LG',       'cat'=>'Monitor',    'price'=>18990000,'special'=>13990000,'qty'=>7],
            ['sku'=>'MON-002','name'=>'Samsung Odyssey G9 49" QLED Curved 240Hz',     'brand'=>'Samsung',  'cat'=>'Monitor',    'price'=>29990000,'special'=>22990000,'qty'=>4],
            ['sku'=>'LAP-001','name'=>'ASUS ROG Zephyrus G14 Ryzen 9 RTX 4070',      'brand'=>'ASUS',     'cat'=>'Laptop',     'price'=>34990000,'special'=>27490000,'qty'=>6],
            ['sku'=>'SSD-001','name'=>'Samsung 990 Pro 2TB NVMe PCIe 5.0',           'brand'=>'Samsung',  'cat'=>'SSD',        'price'=>5490000, 'special'=>3990000, 'qty'=>25],
            ['sku'=>'MBD-001','name'=>'ASUS ROG Maximus Z790 Apex DDR5 ATX',         'brand'=>'ASUS',     'cat'=>'Mainboard',  'price'=>18990000,'special'=>13990000,'qty'=>5],
            ['sku'=>'PSU-001','name'=>'Corsair HX1500i Platinum 1500W Modular',       'brand'=>'Corsair',  'cat'=>'PSU',        'price'=>10990000,'special'=>7990000, 'qty'=>9],

            
            ['sku'=>'GPU-003','name'=>'MSI Gaming X Trio RTX 4060 Ti 16GB',           'brand'=>'MSI',      'cat'=>'GPU',        'price'=>11990000,'special'=>10490000,'qty'=>18],
            ['sku'=>'GPU-004','name'=>'AMD Radeon RX 7900 XTX 24GB GDDR6',           'brand'=>'AMD',      'cat'=>'GPU',        'price'=>22990000,'special'=>19490000,'qty'=>11],
            ['sku'=>'CPU-003','name'=>'AMD Ryzen 7 7700X 8-Core AM5 105W',           'brand'=>'AMD',      'cat'=>'CPU',        'price'=>8490000, 'special'=>7290000, 'qty'=>22],
            ['sku'=>'CPU-004','name'=>'Intel Core i7-14700KF 20-Core Unlocked',       'brand'=>'Intel',    'cat'=>'CPU',        'price'=>9990000, 'special'=>8490000, 'qty'=>14],
            ['sku'=>'RAM-003','name'=>'Kingston Fury Beast 32GB DDR5-5200 RGB',       'brand'=>'Kingston', 'cat'=>'RAM',        'price'=>3490000, 'special'=>2990000, 'qty'=>30],
            ['sku'=>'SSD-002','name'=>'WD Black SN850X 1TB NVMe PCIe 4.0',           'brand'=>'WD',       'cat'=>'SSD',        'price'=>2290000, 'special'=>1990000, 'qty'=>35],
            ['sku'=>'MON-003','name'=>'ASUS TUF Gaming 27" QHD 165Hz IPS',           'brand'=>'ASUS',     'cat'=>'Monitor',    'price'=>7490000, 'special'=>6490000, 'qty'=>13],
            ['sku'=>'MON-004','name'=>'LG 24MP60G 24" IPS Full HD 75Hz Gaming',      'brand'=>'LG',       'cat'=>'Monitor',    'price'=>3490000, 'special'=>2990000, 'qty'=>19],
            ['sku'=>'COL-001','name'=>'Noctua NH-D15 Dual Tower Premium Air Cooler', 'brand'=>'Noctua',   'cat'=>'Cooler',     'price'=>2290000, 'special'=>1990000, 'qty'=>17],
            ['sku'=>'PSU-002','name'=>'Seasonic Focus GX-850 Gold 850W Fully Modular','brand'=>'Seasonic', 'cat'=>'PSU',        'price'=>4490000, 'special'=>3890000, 'qty'=>21],
            ['sku'=>'MBD-002','name'=>'MSI MAG B650 Tomahawk WiFi DDR5 ATX',         'brand'=>'MSI',      'cat'=>'Mainboard',  'price'=>5490000, 'special'=>4690000, 'qty'=>16],
            ['sku'=>'LAP-002','name'=>'Lenovo LOQ 15 i5-13450HX RTX 4060 16GB',      'brand'=>'Lenovo',   'cat'=>'Laptop',     'price'=>22990000,'special'=>19490000,'qty'=>8],

            
            ['sku'=>'ACC-001','name'=>'Thermal Grizzly Kryonaut 1g Thermal Paste',   'brand'=>'Thermal Grizzly','cat'=>'Accessories','price'=>149000,'special'=>119000,'qty'=>100],
            ['sku'=>'ACC-002','name'=>'Noctua NT-H1 3.5g Premium Thermal Compound',  'brand'=>'Noctua',    'cat'=>'Accessories','price'=>199000,'special'=>159000,'qty'=>80],
            ['sku'=>'ACC-003','name'=>'ASUS ROG Large Gaming Mouse Pad 900×400mm',   'brand'=>'ASUS',      'cat'=>'Accessories','price'=>499000,'special'=>399000,'qty'=>50],
            ['sku'=>'ACC-004','name'=>'SteelSeries QcK Medium Gaming Mousepad',      'brand'=>'SteelSeries','cat'=>'Accessories','price'=>299000,'special'=>239000,'qty'=>60],
            ['sku'=>'ACC-005','name'=>'be quiet! Silent Wings 4 120mm PWM Fan',      'brand'=>'be quiet!', 'cat'=>'Accessories','price'=>399000,'special'=>319000,'qty'=>45],
            ['sku'=>'ACC-006','name'=>'Lian Li UNI Fan SL 120mm ARGB Single Fan',    'brand'=>'Lian Li',   'cat'=>'Accessories','price'=>499000,'special'=>399000,'qty'=>40],
            ['sku'=>'ACC-007','name'=>'Ugreen USB-C 4-Port USB 3.0 Hub',             'brand'=>'Ugreen',    'cat'=>'Accessories','price'=>349000,'special'=>279000,'qty'=>70],
            ['sku'=>'ACC-008','name'=>'CableMod Braided ATX 24-Pin Extension 30cm',  'brand'=>'CableMod',  'cat'=>'Accessories','price'=>249000,'special'=>199000,'qty'=>55],
            ['sku'=>'ACC-009','name'=>'Antec Cable Tidy Velcro Ties 50-Pack',        'brand'=>'Antec',     'cat'=>'Accessories','price'=>99000, 'special'=>79000, 'qty'=>200],
            ['sku'=>'ACC-010','name'=>'Phobya Nano Grease Extreme Thermal Paste 1g', 'brand'=>'Phobya',    'cat'=>'Accessories','price'=>149000,'special'=>119000,'qty'=>90],
            ['sku'=>'ACC-011','name'=>'ID-COOLING ZF-12025 120mm Blue LED Case Fan', 'brand'=>'ID-COOLING','cat'=>'Accessories','price'=>229000,'special'=>189000,'qty'=>65],
            ['sku'=>'ACC-012','name'=>'Corsair 2x SATA Power Extension Cable 30cm',  'brand'=>'Corsair',   'cat'=>'Accessories','price'=>189000,'special'=>149000,'qty'=>75],

            
            ['sku'=>'LAP-003','name'=>'Apple MacBook Pro 16 M3 Max 36GB RAM',        'brand'=>'Apple',     'cat'=>'Laptop',     'price'=>69990000,'special'=>null,'qty'=>5],
            ['sku'=>'LAP-004','name'=>'Dell XPS 15 i7-13700H RTX 4060 OLED',         'brand'=>'Dell',      'cat'=>'Laptop',     'price'=>42990000,'special'=>null,'qty'=>7],
            ['sku'=>'LAP-005','name'=>'HP OMEN 16 Ryzen 7 7745HX RTX 4070',          'brand'=>'HP',        'cat'=>'Laptop',     'price'=>29990000,'special'=>null,'qty'=>9],
            ['sku'=>'LAP-006','name'=>'Acer Predator Helios 18 i9-14900HX RTX 4090', 'brand'=>'Acer',      'cat'=>'Laptop',     'price'=>62990000,'special'=>null,'qty'=>4],
            ['sku'=>'GPU-005','name'=>'Gigabyte GeForce RTX 4070 Super Eagle OC 12GB','brand'=>'Gigabyte',  'cat'=>'GPU',        'price'=>16990000,'special'=>null,'qty'=>14],
            ['sku'=>'GPU-006','name'=>'Sapphire Pulse AMD Radeon RX 7800 XT 16GB',   'brand'=>'Sapphire',  'cat'=>'GPU',        'price'=>12990000,'special'=>null,'qty'=>10],
            ['sku'=>'CPU-005','name'=>'Intel Core i5-14600K 14-Core 5.3GHz Gaming',  'brand'=>'Intel',     'cat'=>'CPU',        'price'=>7490000, 'special'=>null,'qty'=>25],
            ['sku'=>'CPU-006','name'=>'AMD Ryzen 5 7600X 6-Core AM5 105W',           'brand'=>'AMD',       'cat'=>'CPU',        'price'=>5490000, 'special'=>null,'qty'=>30],
            ['sku'=>'RAM-004','name'=>'Corsair Vengeance 32GB DDR5-6000 CL36 RGB',   'brand'=>'Corsair',   'cat'=>'RAM',        'price'=>3290000, 'special'=>null,'qty'=>40],
            ['sku'=>'SSD-003','name'=>'Crucial P3 Plus 2TB NVMe PCIe 4.0 M.2',       'brand'=>'Crucial',   'cat'=>'SSD',        'price'=>1890000, 'special'=>null,'qty'=>50],
            ['sku'=>'SSD-004','name'=>'Seagate FireCuda 530 1TB PCIe Gen4 NVMe',     'brand'=>'Seagate',   'cat'=>'SSD',        'price'=>2190000, 'special'=>null,'qty'=>45],
            ['sku'=>'MON-005','name'=>'Dell Alienware AW3423DWF 34" QD-OLED 165Hz',  'brand'=>'Dell',      'cat'=>'Monitor',    'price'=>22990000,'special'=>null,'qty'=>6],
            ['sku'=>'MON-006','name'=>'ASUS ProArt PA278QV 27" QHD IPS 75Hz',        'brand'=>'ASUS',      'cat'=>'Monitor',    'price'=>9490000, 'special'=>null,'qty'=>8],
            ['sku'=>'MBD-003','name'=>'Gigabyte Z790 Aorus Elite AX DDR5 WiFi 6E',   'brand'=>'Gigabyte',  'cat'=>'Mainboard',  'price'=>7990000, 'special'=>null,'qty'=>12],
            ['sku'=>'MBD-004','name'=>'ASRock B650E PG Riptide WiFi AM5 DDR5',       'brand'=>'ASRock',    'cat'=>'Mainboard',  'price'=>4990000, 'special'=>null,'qty'=>18],
            ['sku'=>'PSU-003','name'=>'EVGA SuperNOVA 850 G7 Gold 850W Fully Modular','brand'=>'EVGA',      'cat'=>'PSU',        'price'=>3990000, 'special'=>null,'qty'=>22],
            ['sku'=>'PSU-004','name'=>'be quiet! Dark Power 13 1000W Titanium',       'brand'=>'be quiet!', 'cat'=>'PSU',        'price'=>7490000, 'special'=>null,'qty'=>10],
            ['sku'=>'COL-002','name'=>'NZXT Kraken 360 RGB AIO Liquid Cooler 360mm', 'brand'=>'NZXT',      'cat'=>'Cooler',     'price'=>5990000, 'special'=>null,'qty'=>13],
            ['sku'=>'COL-003','name'=>'Lian Li Galahad II 360 ARGB AIO Liquid',      'brand'=>'Lian Li',   'cat'=>'Cooler',     'price'=>4490000, 'special'=>null,'qty'=>15],
            ['sku'=>'COL-004','name'=>'DeepCool AK620 Dual Tower High Performance',  'brand'=>'DeepCool',  'cat'=>'Cooler',     'price'=>1690000, 'special'=>null,'qty'=>28],
            ['sku'=>'ACC-013','name'=>'Phanteks NV9 Full Tower Premium PC Case',      'brand'=>'Phanteks',  'cat'=>'Accessories','price'=>5990000, 'special'=>null,'qty'=>7],
            ['sku'=>'ACC-014','name'=>'Lian Li O11 Dynamic EVO Tempered Glass Case', 'brand'=>'Lian Li',   'cat'=>'Accessories','price'=>4490000, 'special'=>null,'qty'=>9],
            ['sku'=>'ACC-015','name'=>'Fractal Design Torrent Compact ATX Mid-Tower', 'brand'=>'Fractal',   'cat'=>'Accessories','price'=>3290000, 'special'=>null,'qty'=>11],
        ];

        $productFactory = $om->get(ProductInterfaceFactory::class);
        $productRepo    = $om->get(ProductRepositoryInterface::class);
        $stockRegistry  = $om->get(StockRegistryInterface::class);
        $store          = $storeManager->getStore();

        foreach ($products as $data) {
            
            try {
                $productRepo->get($data['sku']);
                continue;
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                
            }

            
            $product = $productFactory->create();
            $product->setSku($data['sku'])
                ->setName($data['name'])
                ->setAttributeSetId(4) 
                ->setStatus(Status::STATUS_ENABLED)
                ->setVisibility(Visibility::VISIBILITY_BOTH)
                ->setTypeId(Type::TYPE_SIMPLE)
                ->setPrice($data['price'])
                ->setWeight(1)
                ->setStoreId(0)
                ->setWebsiteIds([$store->getWebsiteId()])
                ->setData('brand', $data['brand'])
                ->setShortDescription($data['name'] . ' — ' . $data['brand'] . ' ' . $data['cat'])
                ->setDescription('<p>' . $data['name'] . ' — premium ' . $data['cat'] . ' from ' . $data['brand'] . '. High-performance hardware for enthusiasts.</p>');

            if ($data['special'] !== null) {
                $product->setSpecialPrice($data['special']);
            }

            if (!empty($categoryIds[$data['cat']])) {
                $product->setCategoryIds([$categoryIds[$data['cat']]]);
            }

            $saved = $productRepo->save($product);

            
            $stockItem = $stockRegistry->getStockItemBySku($data['sku']);
            $stockItem->setQty($data['qty']);
            $stockItem->setIsInStock($data['qty'] > 0);
            $stockItem->setManageStock(true);
            $stockRegistry->updateStockItemBySku($data['sku'], $stockItem);
        }

        return $this;
    }
}
