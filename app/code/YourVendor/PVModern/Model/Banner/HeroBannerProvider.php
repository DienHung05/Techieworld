<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Model\Banner;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use YourVendor\PVModern\Model\ProductVisualResolver;

class HeroBannerProvider
{
    private const PLACEMENT = 'homepage-hero';
    private const CMS_BLOCK_IDENTIFIER = 'hero_banner_slider';
    private const MAX_SLIDES = 6;
    private const FEATURED_SKUS = [
        'LAP-DELL-XPS16-9640',
        'APL-IPHONE-16PM-256',
        'DESK-APPLE-MAC-STUDIO-M3U',
        'GPU-ASUS-TUF-RTX5080-OC',
        'MON-SAMSUNG-ODYSSEY-G8-OLED',
        'APL-IPAD-PRO13-M4-256',
        'LAP-ASUS-ZEPHYRUS-G16-4070',
        'MON-DELL-U3225QE',
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly Visibility $visibility,
        private readonly ProductVisualResolver $productVisualResolver,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly AssetRepository $assetRepository,
        private readonly BlockRepositoryInterface $blockRepository
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSlides(string $placement = self::PLACEMENT): array
    {
        if ($placement !== self::PLACEMENT) {
            return [];
        }

        $cmsSlides = $this->loadCmsBlockSlides();
        if (!empty($cmsSlides)) {
            return $cmsSlides;
        }

        $configuredSlides = $this->loadConfiguredSlides();
        if (!empty($configuredSlides)) {
            return $configuredSlides;
        }

        $productSlides = $this->buildProductSlides();
        if (!empty($productSlides)) {
            return $productSlides;
        }

        return $this->fallbackSlides();
    }

    /**
     * Reads the admin CMS block "hero_banner_slider" as JSON slide data.
     *
     * Supported content shapes:
     * [
     *   {"title":"...","image":"/media/wysiwyg/banner.jpg","ctaLink":"/products.html"}
     * ]
     * or {"items":[...]}.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadCmsBlockSlides(): array
    {
        try {
            $cmsBlock = $this->blockRepository->getById(self::CMS_BLOCK_IDENTIFIER);
        } catch (\Throwable $e) {
            return [];
        }

        if (!$cmsBlock->isActive()) {
            return [];
        }

        $content = (string) $cmsBlock->getContent();
        if (trim($content) === '') {
            return [];
        }

        $json = $this->extractJsonPayload($content);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
        if (!$this->isListArray($rows)) {
            $rows = [$rows];
        }

        $slides = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['source'] = 'cms-block:' . self::CMS_BLOCK_IDENTIFIER;
            $normalized = $this->normalizeSlide($row, $index + 1);
            if (!$normalized || !$this->isSlideActive($normalized)) {
                continue;
            }
            $slides[] = $normalized;
        }

        usort($slides, static fn (array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));

        return array_slice($slides, 0, self::MAX_SLIDES);
    }

    private function extractJsonPayload(string $content): string
    {
        $content = preg_replace('/<br\s*\/?>/i', '', $content) ?: $content;
        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = trim($content);

        if ($content === '') {
            return '';
        }

        $arrayStart = strpos($content, '[');
        $arrayEnd = strrpos($content, ']');
        if ($arrayStart !== false && $arrayEnd !== false && $arrayEnd > $arrayStart) {
            return $this->sanitizeJson(substr($content, $arrayStart, $arrayEnd - $arrayStart + 1));
        }

        $objectStart = strpos($content, '{');
        $objectEnd = strrpos($content, '}');
        if ($objectStart !== false && $objectEnd !== false && $objectEnd > $objectStart) {
            return $this->sanitizeJson(substr($content, $objectStart, $objectEnd - $objectStart + 1));
        }

        return '';
    }

    private function sanitizeJson(string $json): string
    {
        return preg_replace('/,\s*([\]}])/m', '$1', trim($json)) ?: trim($json);
    }

    private function isListArray(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        return array_keys($rows) === range(0, count($rows) - 1);
    }

    /**
     * Supports a future CMS/admin export without changing storefront code.
     * Use PVMODERN_HERO_BANNERS_JSON=/absolute/path/to/banners.json or
     * PVMODERN_HERO_BANNERS='[{"title":"...","image":"..."}]'.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadConfiguredSlides(): array
    {
        $json = trim((string) getenv('PVMODERN_HERO_BANNERS'));
        $jsonPath = trim((string) getenv('PVMODERN_HERO_BANNERS_JSON'));

        if ($jsonPath !== '' && is_readable($jsonPath)) {
            $json = (string) file_get_contents($jsonPath);
        }

        if ($json === '') {
            return [];
        }

        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return [];
        }

        $slides = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || empty($row['image'])) {
                continue;
            }

            $normalized = $this->normalizeSlide($row, $index + 1);
            if (!$normalized || !$this->isSlideActive($normalized)) {
                continue;
            }

            $slides[] = $normalized;
        }

        usort($slides, static fn (array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));

        return array_slice($slides, 0, self::MAX_SLIDES);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProductSlides(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId((int) $store->getId())
                ->addStoreFilter($store)
                ->addAttributeToSelect(['name', 'sku', 'price', 'special_price', 'small_image', 'image', 'thumbnail'])
                ->addFinalPrice()
                ->addUrlRewrite()
                ->addAttributeToFilter('status', 1)
                ->setVisibility($this->visibility->getVisibleInCatalogIds())
                ->setPageSize(80)
                ->setCurPage(1);
            $collection->getSelect()->order('e.entity_id DESC');
            $collection->load();
        } catch (\Throwable $e) {
            return [];
        }

        $badges = ['NEW ARRIVAL', 'HOT DEAL', 'TECH TREND', 'JUST UPDATED', 'LIMITED OFFER', 'EDITOR PICK'];
        $themes = ['violet', 'blue', 'slate', 'rose', 'cyan', 'amber'];
        $slides = [];
        $index = 0;

        $products = iterator_to_array($collection, false);
        usort($products, function ($a, $b): int {
            $rankA = $this->featuredRank((string) $a->getSku());
            $rankB = $this->featuredRank((string) $b->getSku());
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return ((float) $b->getFinalPrice()) <=> ((float) $a->getFinalPrice());
        });

        foreach ($products as $product) {
            if (count($slides) >= self::MAX_SLIDES) {
                break;
            }

            $name = trim((string) $product->getName());
            if ($name === '') {
                continue;
            }

            $image = $this->productVisualResolver->resolveProductImage($product, 'category_page_grid');
            if ($image === '' || !$this->isUsableHeroImage($image)) {
                continue;
            }

            $price = (float) $product->getFinalPrice();
            $slides[] = [
                'id' => 'product_' . (int) $product->getId(),
                'placement' => self::PLACEMENT,
                'title' => $name,
                'subtitle' => 'Thiết bị mới cập nhật trên Techieworld. Xem nhanh thông số, giá và tình trạng hàng trước khi thêm vào giỏ.',
                'badge' => $badges[$index % count($badges)],
                'image' => $image,
                'mobileImage' => $image,
                'ctaLabel' => 'Xem sản phẩm',
                'ctaLink' => (string) $product->getProductUrl(),
                'targetType' => 'product',
                'isActive' => true,
                'order' => $index + 1,
                'startDate' => null,
                'endDate' => null,
                'alt' => 'Banner ' . $name,
                'price' => $price > 0
                    ? (string) preg_replace('/[.,]0+(?=\s|[^\d.,]|$)/', '', (string) $this->priceCurrency->format($price, false))
                    : '',
                'theme' => $themes[$index % count($themes)],
                'source' => 'catalog',
            ];
            $index++;
        }

        return $slides;
    }

    private function featuredRank(string $sku): int
    {
        $rank = array_search(strtoupper(trim($sku)), self::FEATURED_SKUS, true);

        return $rank === false ? 1000 : (int) $rank;
    }

    private function isUsableHeroImage(string $imageUrl): bool
    {
        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
            $mediaBase = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            if (!str_starts_with($imageUrl, $mediaBase)) {
                return true;
            }
        }

        $path = (string) (parse_url($imageUrl, PHP_URL_PATH) ?: $imageUrl);
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'media/')) {
            $path = substr($path, 6);
        }

        $absolutePath = BP . '/pub/media/' . $path;
        if (!is_file($absolutePath)) {
            return true;
        }

        $size = @getimagesize($absolutePath);
        if (!is_array($size)) {
            return false;
        }

        return (int) ($size[0] ?? 0) >= 400 && (int) ($size[1] ?? 0) >= 240;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackSlides(): array
    {
        $mediaBase = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        return [
            [
                'id' => 'fallback_gaming_setup',
                'placement' => self::PLACEMENT,
                'title' => 'Công nghệ mới nhất vừa lên kệ',
                'subtitle' => 'Laptop, desktop và phụ kiện nổi bật được tuyển chọn cho nhu cầu học tập, làm việc và gaming.',
                'badge' => 'NEW ARRIVAL',
                'image' => $mediaBase . 'pvmodern/gaming-setup-hero.jpg',
                'mobileImage' => $mediaBase . 'pvmodern/gaming-setup-hero.jpg',
                'ctaLabel' => 'Khám phá ngay',
                'ctaLink' => '#pv3-products',
                'targetType' => 'collection',
                'isActive' => true,
                'order' => 1,
                'startDate' => null,
                'endDate' => null,
                'alt' => 'Banner bộ sưu tập công nghệ mới',
                'price' => '',
                'theme' => 'violet',
                'source' => 'fallback-media',
            ],
            [
                'id' => 'fallback_circuit',
                'placement' => self::PLACEMENT,
                'title' => 'Deal công nghệ mới nhất tuần này',
                'subtitle' => 'Theo dõi các lựa chọn đáng mua từ màn hình, linh kiện PC đến thiết bị Apple.',
                'badge' => 'HOT DEAL',
                'image' => $mediaBase . 'pvmodern/hero-circuit-board.jpg',
                'mobileImage' => $mediaBase . 'pvmodern/hero-circuit-board.jpg',
                'ctaLabel' => 'Xem ưu đãi',
                'ctaLink' => '#pv3-products',
                'targetType' => 'promo',
                'isActive' => true,
                'order' => 2,
                'startDate' => null,
                'endDate' => null,
                'alt' => 'Banner ưu đãi công nghệ',
                'price' => '',
                'theme' => 'blue',
                'source' => 'fallback-media',
            ],
        ];
    }

    private function normalizeSlide(array $row, int $fallbackOrder): ?array
    {
        $themes = ['violet', 'blue', 'rose', 'cyan', 'slate', 'amber'];
        $title = trim((string) ($row['title'] ?? ''));
        $image = trim((string) ($row['image'] ?? ''));
        if ($title === '' || $image === '') {
            return null;
        }

        return [
            'id' => (string) ($row['id'] ?? 'banner_' . $fallbackOrder),
            'placement' => (string) ($row['placement'] ?? self::PLACEMENT),
            'title' => $title,
            'subtitle' => (string) ($row['subtitle'] ?? ''),
            'badge' => (string) ($row['badge'] ?? 'NEW'),
            'image' => $this->normalizeImageUrl($image),
            'mobileImage' => $this->normalizeImageUrl((string) ($row['mobileImage'] ?? $image)),
            'ctaLabel' => (string) ($row['ctaLabel'] ?? 'Xem ngay'),
            'ctaLink' => (string) ($row['ctaLink'] ?? '#pv3-products'),
            'targetType' => (string) ($row['targetType'] ?? 'promo'),
            'isActive' => (bool) ($row['isActive'] ?? true),
            'order' => (int) ($row['order'] ?? $fallbackOrder),
            'startDate' => $row['startDate'] ?? null,
            'endDate' => $row['endDate'] ?? null,
            'alt' => (string) ($row['alt'] ?? $title),
            'price' => (string) ($row['price'] ?? ''),
            'theme' => (string) ($row['theme'] ?? $themes[($fallbackOrder - 1) % count($themes)]),
            'source' => (string) ($row['source'] ?? 'configured'),
        ];
    }

    private function normalizeImageUrl(string $image): string
    {
        $image = trim($image);
        if ($image === '') {
            return $image;
        }

        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $this->preferOriginalWysiwygImage($image);
        }
        if (str_starts_with($image, '/media/')) {
            return $this->preferOriginalWysiwygImage(rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . $image);
        }
        if (str_starts_with($image, '/')) {
            return $this->preferOriginalWysiwygImage($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . ltrim($image, '/'));
        }

        try {
            return $this->preferOriginalWysiwygImage($this->assetRepository->getUrl($image));
        } catch (\Throwable $e) {
            return $this->preferOriginalWysiwygImage($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . ltrim($image, '/'));
        }
    }

    private function preferOriginalWysiwygImage(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if ($path === '' || !str_contains($path, '/media/.thumbswysiwyg/')) {
            return $url;
        }

        $originalPath = str_replace('/media/.thumbswysiwyg/', '/media/wysiwyg/', $path);
        $relativeOriginal = ltrim((string) preg_replace('#^/media/#', '', $originalPath), '/');
        $absoluteOriginal = BP . '/pub/media/' . $relativeOriginal;
        if (!is_file($absoluteOriginal)) {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        $base = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
        $normalized = $base . $originalPath;

        return $query ? $normalized . '?' . $query : $normalized;
    }

    private function isSlideActive(array $slide): bool
    {
        if (empty($slide['isActive'])) {
            return false;
        }

        $now = time();
        $start = !empty($slide['startDate']) ? strtotime((string) $slide['startDate']) : null;
        $end = !empty($slide['endDate']) ? strtotime((string) $slide['endDate']) : null;

        return (!$start || $start <= $now) && (!$end || $end >= $now);
    }
}
