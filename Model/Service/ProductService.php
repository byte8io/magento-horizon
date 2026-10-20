<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Byte8\Horizon\Model\ResourceModel\ProductIndex\CollectionFactory;
use Byte8\Horizon\Model\Catalog\CompositeResolver;
use Byte8\Horizon\Model\Review\SummaryProvider;
use Byte8\Horizon\Model\Sales\SoldSummaryProvider;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;

class ProductService
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ImageHelper $imageHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly SummaryProvider $reviewSummary,
        private readonly SoldSummaryProvider $soldSummary,
        private readonly CompositeResolver $compositeResolver,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Search products using the flat index.
     * Returns shape matching Rust SearchProductsResult.
     */
    public function search(
        string $query,
        int $limit = 10,
        int $page = 1,
        ?int $categoryId = null,
        ?string $productType = null,
        bool $inStockOnly = false,
        ?string $sort = null
    ): array {
        $collection = $this->collectionFactory->create();

        // Only enabled, visible products
        $collection->addFieldToFilter('status', 1);
        $collection->addFieldToFilter('visibility', ['in' => [2, 3, 4]]);

        // Full-text search on name, sku, short_description and the derived
        // search_text (category names, variant options, attribute values).
        // Every term is required first; if that finds nothing, retry with the
        // terms optional so "blue cotton tee" still surfaces tees.
        $terms = $this->searchTerms($query);
        if ($terms !== []) {
            $matched = $this->applyMatch(clone $collection, $terms, true);
            if ($matched->getSize() === 0 && count($terms) > 1) {
                $matched = $this->applyMatch(clone $collection, $terms, false);
            }
            $collection = $matched;
        }

        if ($categoryId !== null) {
            $collection->addFieldToFilter(
                'category_ids_json',
                ['regexp' => '(\\[|,)' . $categoryId . '(\\]|,)']
            );
        }

        if ($productType !== null) {
            $collection->addFieldToFilter('product_type', $productType);
        }

        if ($inStockOnly) {
            $collection->addFieldToFilter('is_in_stock', 1);
        }

        $this->applySort($collection, $sort);

        $totalCount = $collection->getSize();

        $collection->setPageSize($limit);
        $collection->setCurPage($page);

        $store = $this->storeManager->getStore();
        $baseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        $products = [];
        foreach ($collection as $item) {
            $products[] = [
                'id' => (int) $item->getData('product_id'),
                'sku' => $item->getData('sku'),
                'name' => $item->getData('name'),
                'product_type' => $item->getData('product_type'),
                'price' => (float) ($item->getData('price') ?? 0),
                'currency' => $item->getData('currency_code'),
                'in_stock' => (bool) $item->getData('is_in_stock'),
                'url_path' => $item->getData('url_path') ?? '',
                'url' => $this->productUrl((string) ($item->getData('url_path') ?? ''), $store),
                'rating_avg' => $item->getData('rating_avg') !== null ? (float) $item->getData('rating_avg') : null,
                'review_count' => (int) ($item->getData('review_count') ?? 0),
                'sold_qty' => (float) ($item->getData('sold_qty') ?? 0),
                'options' => $this->decodeOptions($item->getData('options_json')),
            ];
        }

        return [
            'products' => $products,
            'total_count' => $totalCount,
            'page' => $page,
            'page_size' => $limit,
        ];
    }

    /**
     * Get full product detail by SKU or numeric ID.
     * Returns shape matching Rust ProductDetail.
     */
    public function getDetail(string $identifier): ?array
    {
        // Try by SKU first, then by ID
        try {
            if (is_numeric($identifier)) {
                $product = $this->productRepository->getById((int) $identifier);
            } else {
                $product = $this->productRepository->get($identifier);
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // If numeric lookup by ID failed, it might also be a SKU
            if (is_numeric($identifier)) {
                try {
                    $product = $this->productRepository->get($identifier);
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    return null;
                }
            } else {
                return null;
            }
        }

        $store = $this->storeManager->getStore();
        $mediaBaseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        // Images
        $images = [];
        $gallery = $product->getMediaGalleryEntries() ?? [];
        foreach ($gallery as $pos => $entry) {
            $images[] = [
                'url' => $mediaBaseUrl . 'catalog/product' . $entry->getFile(),
                'label' => $entry->getLabel(),
                'position' => (int) ($entry->getPosition() ?? $pos),
                'is_main' => in_array('image', $entry->getTypes() ?? []),
            ];
        }

        // Categories
        $categories = [];
        foreach ($product->getCategoryIds() as $catId) {
            try {
                $cat = $this->categoryRepository->get((int) $catId);
                $categories[] = [
                    'id' => (int) $cat->getId(),
                    'name' => $cat->getName(),
                    'url_path' => $cat->getUrlPath() ?? '',
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        // Attributes
        $attributes = [];
        $filterableAttrs = ['color', 'size', 'manufacturer', 'material', 'pattern'];
        foreach ($filterableAttrs as $attrCode) {
            $value = $product->getAttributeText($attrCode);
            if ($value && $value !== false) {
                $attr = $product->getResource()->getAttribute($attrCode);
                $attributes[] = [
                    'code' => $attrCode,
                    'label' => $attr ? $attr->getStoreLabel() : $attrCode,
                    'value' => is_array($value) ? implode(', ', $value) : (string) $value,
                ];
            }
        }

        // Reviews (approved only, live — not from the index)
        $rating = $this->reviewSummary->forProductIds([(int) $product->getId()])[(int) $product->getId()] ?? null;
        $composite = $this->compositeResolver->resolve($product);
        $soldQty = $this->soldSummary->forProductIds([(int) $product->getId()])[(int) $product->getId()] ?? 0.0;

        // Stock
        $stockItem = $product->getExtensionAttributes()?->getStockItem();
        $qty = $stockItem ? (float) $stockItem->getQty() : null;
        $inStock = $stockItem ? (bool) $stockItem->getIsInStock() : false;

        return [
            'id' => (int) $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'product_type' => $product->getTypeId(),
            'description' => $product->getDescription(),
            'short_description' => $product->getShortDescription(),
            'price' => (float) ($product->getPrice() ?? 0),
            'special_price' => $product->getSpecialPrice() ? (float) $product->getSpecialPrice() : null,
            'currency' => $store->getBaseCurrencyCode(),
            'in_stock' => $inStock,
            'qty' => $qty,
            'url_path' => $product->getUrlKey() ?? '',
            'url' => $this->productUrl((string) ($product->getUrlKey() ?? ''), $store),
            'images' => $images,
            'categories' => $categories,
            'attributes' => $attributes,
            'rating_avg' => $rating['avg'] ?? null,
            'review_count' => $rating['count'] ?? 0,
            'sold_qty' => $soldQty,
            'options' => $composite['options'],
        ];
    }

    /**
     * Ranked browsing for "best sellers" / "top rated" style questions.
     * Unknown or null sort keeps the collection's natural (relevance) order.
     */
    private function applySort(\Byte8\Horizon\Model\ResourceModel\ProductIndex\Collection $collection, ?string $sort): void
    {
        switch ($sort) {
            case 'best_selling':
                $collection->addFieldToFilter('sold_qty', ['gt' => 0]);
                $collection->setOrder('sold_qty', 'DESC');
                $collection->setOrder('rating_avg', 'DESC');
                break;
            case 'top_rated':
                $collection->addFieldToFilter('review_count', ['gt' => 0]);
                $collection->setOrder('rating_avg', 'DESC');
                $collection->setOrder('review_count', 'DESC');
                break;
            case 'price_asc':
                $collection->setOrder('price', 'ASC');
                break;
            case 'price_desc':
                $collection->setOrder('price', 'DESC');
                break;
            default:
                break;
        }
    }

    /**
     * Absolute storefront URL for a product: store base URL (incl. store code
     * when "Add Store Code to URLs" is on) + URL key + the configured product
     * URL suffix. Empty when the product has no URL key.
     */
    private function productUrl(string $urlKey, StoreInterface $store): string
    {
        if ($urlKey === '') {
            return '';
        }
        $suffix = (string) $this->scopeConfig->getValue(
            'catalog/seo/product_url_suffix',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
        if ($suffix !== '' && $suffix[0] !== '.') {
            $suffix = '.' . $suffix;
        }
        return rtrim($store->getBaseUrl(), '/') . '/' . ltrim($urlKey, '/') . $suffix;
    }

    /**
     * Prepare query for MySQL MATCH AGAINST in boolean mode.
     */
    /**
     * Retail vocabulary that rarely matches catalogue wording verbatim. Each
     * query word is expanded to itself OR its synonyms; merchant-managed
     * Magento search synonym groups are merged in when present.
     */
    private const SYNONYMS = [
        'tshirt' => ['tee'],
        'tshirts' => ['tee'],
        'tees' => ['tee'],
        'trousers' => ['pants'],
        'jumper' => ['sweater', 'hoodie'],
        'pullover' => ['sweater'],
        'hoody' => ['hoodie'],
        'trainers' => ['sneakers', 'shoes'],
        'rucksack' => ['backpack'],
        'mens' => ['men'],
        'womens' => ['women'],
        'ladies' => ['women'],
        'gents' => ['men'],
    ];

    /** InnoDB default innodb_ft_min_token_size; shorter tokens never match. */
    private const MIN_TOKEN = 3;

    /**
     * @return string[][] one entry per query word: [word, synonym, ...] (prefix-matched)
     */
    private function searchTerms(string $query): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '' || $query === '*') {
            return [];
        }
        // "t-shirt" / "t shirt" would otherwise split into a 1-char token and "shirt".
        $query = preg_replace('/\bt[\s\-]?shirts?\b/u', 'tee', $query) ?? $query;
        $query = str_replace(['-', '/', '_'], ' ', $query);

        $magentoGroups = $this->magentoSynonymGroups();
        $terms = [];
        foreach (preg_split('/\s+/', $query) ?: [] as $word) {
            $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word) ?? '';
            if (mb_strlen($word) < self::MIN_TOKEN) {
                continue;
            }
            $variants = [$word];
            foreach (self::SYNONYMS[$word] ?? [] as $synonym) {
                $variants[] = $synonym;
            }
            foreach ($magentoGroups as $group) {
                if (in_array($word, $group, true)) {
                    $variants = array_merge($variants, $group);
                }
            }
            $terms[] = array_values(array_unique(array_filter(
                $variants,
                static fn (string $v): bool => mb_strlen($v) >= self::MIN_TOKEN
            )));
        }
        return $terms;
    }

    /**
     * @param string[][] $terms
     */
    private function applyMatch(
        \Byte8\Horizon\Model\ResourceModel\ProductIndex\Collection $collection,
        array $terms,
        bool $requireAll
    ): \Byte8\Horizon\Model\ResourceModel\ProductIndex\Collection {
        $parts = [];
        foreach ($terms as $variants) {
            $group = implode(' ', array_map(static fn (string $v): string => $v . '*', $variants));
            $parts[] = ($requireAll ? '+' : '') . (count($variants) > 1 ? '(' . $group . ')' : $group);
        }
        $connection = $this->resourceConnection->getConnection();
        $against = $connection->quote(implode(' ', $parts));
        $match = 'MATCH(name, sku, short_description, search_text) AGAINST(' . $against . ' IN BOOLEAN MODE)';
        $collection->getSelect()->where($match);
        $collection->getSelect()->order(new \Zend_Db_Expr($match . ' DESC'));
        return $collection;
    }

    /**
     * Magento admin → Marketing → Search Synonyms, as groups of lower-cased words.
     *
     * @return string[][]
     */
    private function magentoSynonymGroups(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('search_synonyms');
            if (!$connection->isTableExists($table)) {
                return [];
            }
            $groups = [];
            foreach ($connection->fetchCol($connection->select()->from($table, ['synonyms'])) as $row) {
                $words = array_values(array_filter(array_map(
                    static fn (string $w): string => mb_strtolower(trim($w)),
                    explode(',', (string) $row)
                )));
                if (count($words) > 1) {
                    $groups[] = $words;
                }
            }
            return $groups;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{code: string, label: string, values: string[]}>
     */
    private function decodeOptions(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
