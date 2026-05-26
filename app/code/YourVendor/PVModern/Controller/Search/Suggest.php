<?php
declare(strict_types=1);

namespace YourVendor\PVModern\Controller\Search;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use YourVendor\PVModern\Model\ProductVisualResolver;

/**
 * GET /pvmodern/search/suggest?q=<query>
 * Returns up to 10 products matching the query — name, image, price, URL.
 */
class Suggest implements HttpGetActionInterface
{
    private const MAX_RESULTS = 10;

    public function __construct(
        private readonly RequestInterface        $request,
        private readonly JsonFactory             $jsonFactory,
        private readonly CollectionFactory       $collectionFactory,
        private readonly Visibility              $visibility,
        private readonly ImageHelper             $imageHelper,
        private readonly StockHelper             $stockHelper,
        private readonly PriceCurrencyInterface  $priceCurrency,
        private readonly StoreManagerInterface   $storeManager,
        private readonly ProductVisualResolver   $productVisualResolver
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache', true);

        $q = $this->normalizeQuery((string) $this->request->getParam('q', ''));
        if (mb_strlen($q) < 1) {
            return $result->setData(['items' => [], 'total' => 0, 'query' => $q]);
        }

        try {
            $store = $this->storeManager->getStore();
            $like  = '%' . addcslashes($q, '%_\\') . '%';

            $collection = $this->collectionFactory->create();
            $collection
                ->setStoreId((int) $store->getId())
                ->addStoreFilter($store)
                ->addAttributeToSelect(['name', 'sku', 'small_image', 'image', 'thumbnail', 'price', 'special_price'])
                ->addFinalPrice()
                ->addUrlRewrite()
                ->addAttributeToFilter('status', 1)
                ->setVisibility($this->visibility->getVisibleInSiteIds())
                ->addAttributeToFilter(
                    [
                        ['attribute' => 'name', 'like' => $like],
                        ['attribute' => 'sku', 'like' => $like],
                    ]
                );

            $this->stockHelper->addInStockFilterToCollection($collection);

            $collection
                ->setPageSize(40)
                ->setCurPage(1);

            $items = [];
            foreach ($collection as $product) {
                $finalPrice   = (float) $product->getFinalPrice();
                $regularPrice = (float) $product->getPrice();
                $discount     = ($regularPrice > $finalPrice && $regularPrice > 0)
                    ? (int) round((1 - ($finalPrice / $regularPrice)) * 100)
                    : null;

                $items[] = [
                    'id'            => (int) $product->getId(),
                    'name'          => (string) $product->getName(),
                    'sku'           => (string) $product->getSku(),
                    'url'           => (string) $product->getProductUrl(),
                    'image'         => $this->productVisualResolver->resolveProductImage($product, 'product_thumbnail_image'),
                    'price'         => $this->stripDecimals((string) $this->priceCurrency->format($finalPrice, false)),
                    'original_price'=> $discount ? $this->stripDecimals((string) $this->priceCurrency->format($regularPrice, false)) : null,
                    'discount'      => $discount,
                    '_score'        => $this->calculateScore($product, $q),
                ];
            }

            usort(
                $items,
                static function (array $left, array $right): int {
                    if ($left['_score'] === $right['_score']) {
                        return strcmp($left['name'], $right['name']);
                    }

                    return $right['_score'] <=> $left['_score'];
                }
            );

            $items = array_slice($items, 0, self::MAX_RESULTS);
            foreach ($items as &$item) {
                unset($item['_score']);
            }
            unset($item);

            return $result->setData([
                'items' => $items,
                'total' => count($items),
                'query' => $q,
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['items' => [], 'total' => 0, 'query' => $q, 'error' => $e->getMessage()]);
        }
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');

        return $query;
    }

    private function stripDecimals(string $formatted): string
    {
        return (string) preg_replace('/[.,]0+(?=\s|[^\d.,]|$)/', '', $formatted);
    }

    private function calculateScore(\Magento\Catalog\Model\Product $product, string $query): int
    {
        $query = mb_strtolower($query);
        $name = mb_strtolower((string) $product->getName());
        $sku = mb_strtolower((string) $product->getSku());
        $score = 0;
        $position = mb_strpos($name, $query);

        if ($position === 0) {
            $score += 600;
        } elseif ($position !== false) {
            $score += max(220, 420 - ($position * 8));
        }

        if (preg_match('/\b' . preg_quote($query, '/') . '/u', $name) === 1) {
            $score += 160;
        }

        foreach (array_filter(explode(' ', $query)) as $term) {
            if (preg_match('/\b' . preg_quote($term, '/') . '/u', $name) === 1) {
                $score += 45;
            } elseif (mb_strpos($name, $term) !== false) {
                $score += 20;
            }
        }

        if (mb_strpos($sku, $query) === 0) {
            $score += 140;
        } elseif (mb_strpos($sku, $query) !== false) {
            $score += 80;
        }

        if ((float) $product->getFinalPrice() > 0.0) {
            $score += 8;
        }

        return $score;
    }
}
