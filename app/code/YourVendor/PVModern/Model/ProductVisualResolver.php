<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;

class ProductVisualResolver
{
    private const PLACEHOLDER_ASSET = 'YourVendor_PVModern::images/placeholder.jpg';
    private const LOCAL_IMAGE_DIRS = [
        'pvmodern/products',
        'import/pvmodern-image-sync',
    ];

    
    private const EXACT_SKU_MAP = [
        'APL-WATCH-S10-46' => 'https://www.apple.com/newsroom/images/2024/09/introducing-apple-watch-series-10/article/Apple-Watch-Series-10-lineup-240909_big.jpg.large.jpg',
        'APL-WATCH-ULTRA2-BLACK' => 'https://www.apple.com/assets-www/en_WW/watch/og/watch_og_1ff2ee953.png',
        'APL-AIRPODS-PRO2-USBC' => 'https://www.apple.com/v/airpods-pro/r/images/meta/og__c0ceegchesom_overview.png?202603242312',
        'APL-AIRPODS-4-ANC' => 'https://www.apple.com/v/airpods-4/g/images/meta/airpods-4__gnjh1t3yjxm6_og.png?202603242312',
        'APL-AIRPODSMAX-USBC' => 'https://www.apple.com/v/airpods-max/k/images/meta/airpods-max_overview__c2mz40a3bugm_og.png?202604101202',
        'APL-IPHONE-16PM-256' => 'https://www.apple.com/v/iphone/home/cj/images/meta/iphone__bh930eyjnj0i_og.png?202604011630',
        'APL-IPHONE-16-128' => 'https://store.storeimages.cdn-apple.com/1/as-images.apple.com/is/iphone-16-model-unselect-gallery-1-202409?wid=1200&hei=630&fmt=jpeg&qlt=95&.v=1723991997435',
        'APL-IPAD-PRO13-M4-256' => 'https://www.apple.com/v/ipad-pro/aw/images/meta/ipad-pro_overview__bu4cql27diaa_og.png?202604021101',
        'APL-IPAD-AIR13-M2-128' => 'https://www.apple.com/v/ipad-air/ah/images/meta/ipad-air_overview__bc2fd15uec0y_og.png?202603101707',
        'DESK-APPLE-MAC-STUDIO-M3U' => 'https://www.apple.com/v/mac-studio/l/images/meta/mac-studio_overview__eedzbosm1t26_og.png?202603261117',
        'DESK-APPLE-MAC-MINI-M4P' => 'https://www.apple.com/v/mac-mini/aa/images/meta/mac-mini__dvce2jrm11w2_og.jpg?202601201341',
        'LAP-APPLE-MBA15-M3' => 'https://www.apple.com/v/macbook-air/z/images/meta/macbook_air_mx__ez5y0k5yy7au_og.png?202603261117',
        'LAP-APPLE-MBA13-M3' => 'https://www.apple.com/v/macbook-air/z/images/meta/macbook_air_mx__ez5y0k5yy7au_og.png?202603261117',
        'LAP-APPLE-MBP14-M4PRO' => 'https://www.apple.com/v/macbook-pro/ax/images/meta/macbook-pro__difvbgz1plsi_og.png?202603261117',
        'LAP-DELL-XPS16-9640' => 'https://i.dell.com/is/image/DellContent/content/dam/ss2/product-images/dell-client-products/notebooks/xps-notebooks/16-9640/media-gallery/silver/touch/notebook-laptop-xps-16-9640-t-silver-gallery-2.psd?chrss=full&fmt=png-alpha&hei=402&pscan=auto&qlt=100%2C1&resMode=sharp2&scl=1&size=612%2C402&wid=612',
        'CPU-AMD-RYZEN 9 9900X-AM5' => 'https://www.amd.com/content/dam/amd/en/images/products/processors/ryzen/2613900-ryzen-9-9950x-og.jpg',
        'CPU-AMD-RYZEN 7 9800X3D-AM5' => 'https://www.amd.com/content/dam/amd/en/images/products/processors/ryzen/2900400-ryzen-7-9800x3d.jpg',
        'CPU-AMD-RYZEN 7 9700X-AM5' => 'https://www.amd.com/content/dam/amd/en/images/products/processors/ryzen/2613900-ryzen-7-9700x-og.jpg',
        'GPU-ASUS-TUF-RTX5080-OC' => 'https://dlcdnwebimgs.asus.com/gain/7bb494af-8636-46a3-93ee-3cff60d30624/',
        'GPU-ASUS-PRIME-RTX5070TI-OC' => 'https://dlcdnwebimgs.asus.com/gain/11a24dd6-9a6b-415a-9131-afad0064b16c/',
        'MB-ASUS-ROG-STRIX-X870E-E' => 'https://dlcdnwebimgs.asus.com/gain/C2A10896-76C0-4772-9A3A-69D7B0C00441',
        'MB-GIGABYTE-X870E-AORUS-MASTER' => 'https://hacom.vn/media/product/86666_mainboard_gigabyte_x870e_aorus_master__3_.jpg',
        'MB-GIGABYTE-B650E-AORUS-ELITE-AX' => 'https://hacom.vn/media/product/78727_mainboard_gigabyte_650m_aorus_elite_ax_ice.jpg',
        'MB-MSI-MPG-X870E-CARBON' => 'https://hacom.vn/media/product/86502_mainboard_msi_mpg_x870e_carbon_wifi__1_.jpg',
        'MON-APPLE-STUDIO-DISPLAY' => 'https://www.apple.com/v/studio-display/f/images/meta/studio-display_overview__cc7vair07fjm_og.png?202603261117',
        'MON-ASUS-PROART-PA32' => 'https://dlcdnwebimgs.asus.com/gain/65040add-7168-41db-8e16-dde55f47940b/w692',
        'MON-SAMSUNG-ODYSSEY-G8-OLED' => 'https://images.samsung.com/is/image/samsung/p6pim/us/ls32fg810snxza/gallery/us-odyssey-oled-g8-27g81sf-ls32fg810snxza-550914457?$product-details-jpg$',
    ];

    private const SKU_MAP = [
        'CPU-' => 'https://www.amd.com/content/dam/amd/en/images/products/processors/ryzen/2613900-ryzen-7-9700x-og.jpg',
        'VGA-' => 'https://dlcdnwebimgs.asus.com/gain/7bb494af-8636-46a3-93ee-3cff60d30624/',
        'GPU-' => 'https://dlcdnwebimgs.asus.com/gain/7bb494af-8636-46a3-93ee-3cff60d30624/',
        'MAINBOARD-' => 'https://hacom.vn/media/product/86666_mainboard_gigabyte_x870e_aorus_master__3_.jpg',
        'MB-' => 'https://hacom.vn/media/product/86666_mainboard_gigabyte_x870e_aorus_master__3_.jpg',
        'RAM-' => 'https://assets.corsair.com/image/upload/c_scale%2Cq_auto%2Cw_960/products/Memory/Dominator%20Titanium/enhanced%20images/black/2UP/DOMINATOR_TITANIUM_RGB_DDR5_BLACK_2UP_Artboard01_AA.png',
        'SSD-' => 'https://www.seagate.com/content/dam/seagate/migrated-assets/www-content/product-content/firecuda-family/firecuda-530-ssd/en-us/images/firecuda-530-ssd-za2000gm1a013-front-angle-drive.png',
        'HDD-' => 'https://www.seagate.com/content/dam/seagate/assets/products/nas-drives/ironwolf-hard-drive/ironwolf-hdd-product-card-image.png',
        'PSU-' => 'https://res.cloudinary.com/corsair-pwa/image/upload/c_scale%2Cq_auto%2Cw_960/v1680716095/products/Power-Supply-Units/base-rmx-shift-series-psu-config/CP-9020252/Gallery/RM850x_SHIFT_01.png',
        'COOL-' => 'https://assets.corsair.com/image/upload/c_scale%2Cq_auto%2Cw_960/products/Liquid-Cooling/titan-rx-rgb/Gallery/CW-9061018-WW/CW-9061018-WW_01.png',
        'FAN-' => 'https://res.cloudinary.com/corsair-pwa/image/upload/c_scale%2Cq_auto%2Cw_960/products/Fans/CO-9051002-WW/iCUE_LINK_QX120_RGB_BLACK_TRIPLE_Artboard01_AA.png',
        'CASE-' => 'https://assets.corsair.com/image/upload/c_scale%2Cq_auto%2Cw_960/products/Cases/base-5000d-core-airflow/Gallery/CC-9011261-WW/CC-9011261-WW_01.png',
        'DESK-TW-' => 'https://assets.corsair.com/image/upload/c_scale%2Cq_auto%2Cw_960/products/Cases/base-5000d-core-airflow/Gallery/CC-9011261-WW/CC-9011261-WW_01.png',
        'LAP-APPLE-MBA' => 'https://www.apple.com/v/macbook-air/z/images/meta/macbook_air_mx__ez5y0k5yy7au_og.png?202603261117',
        'LAP-APPLE-MBP' => 'https://www.apple.com/v/macbook-pro/ax/images/meta/macbook-pro__difvbgz1plsi_og.png?202603261117',
        'DESK-APPLE-' => 'https://www.apple.com/v/mac-mini/aa/images/meta/mac-mini__dvce2jrm11w2_og.jpg?202601201341',
        'APL-IPHONE-' => 'https://www.apple.com/v/iphone/home/cj/images/meta/iphone__bh930eyjnj0i_og.png?202604011630',
        'APL-IPAD-' => 'https://www.apple.com/v/ipad-pro/aw/images/meta/ipad-pro_overview__bu4cql27diaa_og.png?202604021101',
        'APL-AIRPODS-' => 'https://www.apple.com/v/airpods-pro/r/images/meta/og__c0ceegchesom_overview.png?202603242312',
        'APL-WATCH-' => 'https://www.apple.com/newsroom/images/2024/09/introducing-apple-watch-series-10/article/Apple-Watch-Series-10-lineup-240909_big.jpg.large.jpg',
        'LAP-ASUS-' => 'https://dlcdnwebimgs.asus.com/files/media/ef4bcfba-86fb-4c01-bf0f-4a6d869cc0ce/v1/features/images/large/1x/kv-product.png',
        'LAP-LENOVO-' => 'https://p1-ofp.static.pub//fes/cms/2024/01/08/6tw4zjz29pwme3pl4lp3ehzbvkmyns252871.png',
        'LAP-MSI-' => 'https://storage-asset.msi.com/global/picture/image/feature/nb/2024/Raider-18-HX-A14V/Raider18-HX-A14V-kv.png',
        'LAP-ACER-' => 'https://static.acer.com/up/Resource/Acer/Laptops/Predator_Helios_16/Images/20240105/Predator-Helios-16-ph16-72-main.png',
        'LAP-HP-' => 'https://ssl-product-images.www8-hp.com/digmedialib/prodimg/lowres/c08958821.png',
        'LAP-DELL-' => 'https://i.dell.com/is/image/DellContent/content/dam/ss2/product-images/dell-client-products/notebooks/xps-notebooks/16-9640/spi/touch/silver/notebook-xps-16-9640-t-silver-campaign-hero-504x350-ng.psd?fmt=jpg&wid=1200&hei=840',
        'MON-SAMSUNG-' => 'https://images.samsung.com/is/image/samsung/p6pim/us/ls32fg810snxza/gallery/us-odyssey-oled-g8-27g81sf-ls32fg810snxza-550914457?$product-details-jpg$',
        'MON-LG-' => 'https://images.samsung.com/is/image/samsung/p6pim/us/ls32fg810snxza/gallery/us-odyssey-oled-g8-27g81sf-ls32fg810snxza-550914457?$product-details-jpg$',
        'MON-ASUS-' => 'https://dlcdnwebimgs.asus.com/gain/65040add-7168-41db-8e16-dde55f47940b/w692',
        'MON-DELL-' => 'https://i.dell.com/is/image/DellContent/content/dam/ss2/product-images/peripherals/output-devices/dell/monitors/u4025qw/media-gallery/monitor-u4025qw-gallery-1.psd?fmt=png-alpha&wid=1200&hei=900',
        'MON-MSI-' => 'https://images.samsung.com/is/image/samsung/p6pim/us/ls32fg810snxza/gallery/us-odyssey-oled-g8-27g81sf-ls32fg810snxza-550914457?$product-details-jpg$',
        'MON-BENQ-' => 'https://dlcdnwebimgs.asus.com/gain/65040add-7168-41db-8e16-dde55f47940b/w692',
    ];

    public function __construct(
        private readonly ImageHelper $imageHelper,
        private readonly AssetRepository $assetRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function resolveProductImage(Product $product, string $imageId = 'category_page_grid'): string
    {
        if ($localImage = $this->resolveLocalSkuImage((string) $product->getSku())) {
            return $localImage;
        }

        try {
            $resolved = (string) $this->imageHelper->init($product, $imageId)->getUrl();
            if (!$this->isPlaceholder($resolved)) {
                return $resolved;
            }
        } catch (\Throwable $e) {
        }

        return $this->resolveBySku((string) $product->getSku());
    }

    public function resolveBySku(string $sku): string
    {
        $sku = strtoupper(trim($sku));

        if ($localImage = $this->resolveLocalSkuImage($sku)) {
            return $localImage;
        }

        if (!empty(self::EXACT_SKU_MAP[$sku])) {
            return $this->normalizeImageUrl(self::EXACT_SKU_MAP[$sku]);
        }

        
        
        
        
        return $this->assetRepository->getUrl(self::PLACEHOLDER_ASSET);
    }

    private function resolveLocalSkuImage(string $sku): ?string
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($sku)) ?: strtolower($sku));

        foreach (self::LOCAL_IMAGE_DIRS as $directory) {
            $baseDir = BP . '/pub/media/' . $directory . '/';

            foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
                $absolutePath = $baseDir . $normalized . '.' . $extension;
                if (!is_file($absolutePath)) {
                    continue;
                }

                return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
                    . $directory . '/'
                    . basename($absolutePath);
            }
        }

        return null;
    }

    private function normalizeImageUrl(string $image): string
    {
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }

        if (str_starts_with($image, '/')) {
            return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . ltrim($image, '/');
        }

        return $this->assetRepository->getUrl($image);
    }

    private function isPlaceholder(string $url): bool
    {
        if ($url === '') {
            return true;
        }

        return str_contains($url, 'placeholder')
            || str_contains($url, 'no_selection');
    }
}
