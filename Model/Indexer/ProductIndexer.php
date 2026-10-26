<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Indexer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Byte8\Horizon\Model\Catalog\AttributeTextResolver;
use Byte8\Horizon\Model\Catalog\CompositeResolver;
use Byte8\Horizon\Model\Review\SummaryProvider;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Byte8\Horizon\Model\Sales\SoldSummaryProvider;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ProductIndexer
{
    private const TABLE = 'byte8_horizon_product_index';
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly SummaryProvider $reviewSummary,
        private readonly SoldSummaryProvider $soldSummary,
        private readonly CompositeResolver $compositeResolver,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly AttributeTextResolver $attributeTextResolver
    ) {
    }

    /**
     * Full reindex: truncate and rebuild the entire flat index.
     *
     * Normally invoked through the framework (`indexer:reindex byte8_horizon_product`
     * → {@see \Byte8\Horizon\Model\Indexer\Product\Action::executeFull()}).
     */
    public function reindexAll(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->truncateTable($table);

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(array_merge([
            'name', 'sku', 'short_description', 'description', 'price', 'special_price',
            'url_key', 'image', 'visibility', 'status', 'type_id',
        ], $this->attributeTextResolver->selectableCodes()));
        $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);

        $currency = $this->storeManager->getStore()->getBaseCurrencyCode();
        $ratings = $this->reviewSummary->forAllProducts();
        $sold = $this->soldSummary->forAllProducts();
        $categoryNames = $this->categoryNames();
        $count = 0;
        $batch = [];

        foreach ($collection as $product) {
            $batch[] = $this->buildRow(
                $product,
                $currency,
                $ratings[(int) $product->getId()] ?? null,
                $sold[(int) $product->getId()] ?? 0.0,
                $categoryNames
            );
            $count++;

            if (count($batch) >= self::BATCH_SIZE) {
                $connection->insertMultiple($table, $batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $connection->insertMultiple($table, $batch);
        }

        $this->logger->info("Horizon: reindexed {$count} products");
        return $count;
    }

    /**
     * Partial reindex by SKU. Resolves each SKU to its product_id via
     * catalog_product_entity, calls reindexByIds, and returns the resolved IDs
     * so the caller can pass them to the webhook Notifier.
     *
     * SKUs that don't resolve are silently dropped.
     *
     * @param string[] $skus
     * @return int[]
     */
    public function reindexBySkus(array $skus): array
    {
        $productIds = $this->resolveProductIds($skus);
        $this->reindexByIds($productIds);
        return $productIds;
    }

    /**
     * Resolve SKUs to product IDs via catalog_product_entity. Unknown SKUs are dropped.
     *
     * @param string[] $skus
     * @return int[]
     */
    public function resolveProductIds(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus, 'strlen')));
        if (empty($skus)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('sku IN (?)', $skus);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Partial reindex: update specific product IDs. Products that no longer
     * exist or are disabled lose their row, so this doubles as the delete path.
     */
    public function reindexByIds(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        // Delete existing rows for these products
        $connection->delete($table, ['product_id IN (?)' => $productIds]);

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(array_merge([
            'name', 'sku', 'short_description', 'description', 'price', 'special_price',
            'url_key', 'image', 'visibility', 'status', 'type_id',
        ], $this->attributeTextResolver->selectableCodes()));
        $collection->addIdFilter($productIds);
        $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);

        $currency = $this->storeManager->getStore()->getBaseCurrencyCode();
        $ratings = $this->reviewSummary->forProductIds($productIds);
        $sold = $this->soldSummary->forProductIds($productIds);
        $categoryNames = $this->categoryNames();
        $rows = [];

        foreach ($collection as $product) {
            $rows[] = $this->buildRow(
                $product,
                $currency,
                $ratings[(int) $product->getId()] ?? null,
                $sold[(int) $product->getId()] ?? 0.0,
                $categoryNames
            );
        }

        if (!empty($rows)) {
            $connection->insertMultiple($table, $rows);
        }
    }

    /**
     * @param array{avg: float|null, count: int}|null $rating Approved-review summary for this product
     * @param float $soldQty Units sold in the last SoldSummaryProvider::WINDOW_DAYS days
     * @param array<int, string> $categoryNames category id => name, for the full-text column
     */
    private function buildRow(
        \Magento\Catalog\Model\Product $product,
        string $currency,
        ?array $rating,
        float $soldQty = 0.0,
        array $categoryNames = []
    ): array {
        // Stock info
        $isInStock = false;
        $qty = 0.0;
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($product->getSku());
            $isInStock = (bool) $stockItem->getIsInStock();
            $qty = (float) $stockItem->getQty();
        } catch (\Exception $e) {
            // Product might not have stock item (virtual/downloadable)
        }

        // Categories
        $categoryIds = $product->getCategoryIds();

        // Filterable attributes (colour, size, brand…) — only those this catalogue has
        $attributes = $this->attributeTextResolver->resolve($product);

        $composite = $this->compositeResolver->resolve($product);

        // Full-text helper: words a shopper uses that aren't in the name —
        // "men", "tees", "blue", "M" — live in categories and variants.
        $searchWords = [];
        foreach ($categoryIds as $categoryId) {
            if (isset($categoryNames[(int) $categoryId])) {
                $searchWords[] = $categoryNames[(int) $categoryId];
            }
        }
        foreach ($composite['options'] as $option) {
            $searchWords[] = $option['label'];
            $searchWords = array_merge($searchWords, $option['values']);
        }
        foreach ($attributes as $attribute) {
            $searchWords[] = $attribute['value'];
        }
        $searchText = trim(implode(' ', array_unique(array_filter($searchWords))));

        return [
            'product_id' => (int) $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'short_description' => $product->getShortDescription(),
            'description' => $product->getDescription(),
            'product_type' => $product->getTypeId(),
            'price' => $composite['price'],
            'special_price' => $composite['special_price'],
            'currency_code' => $currency,
            'is_in_stock' => $isInStock ? 1 : 0,
            'qty' => $qty,
            'url_path' => $product->getUrlKey(),
            'main_image_url' => $product->getImage() && $product->getImage() !== 'no_selection'
                ? $product->getImage()
                : null,
            'category_ids_json' => !empty($categoryIds) ? json_encode(array_map('intval', $categoryIds)) : null,
            'attributes_json' => !empty($attributes) ? json_encode($attributes) : null,
            'search_text' => $searchText !== '' ? $searchText : null,
            'options_json' => $composite['options'] !== [] ? json_encode($composite['options']) : null,
            'visibility' => (int) $product->getVisibility(),
            'status' => (int) $product->getStatus(),
            'rating_avg' => $rating['avg'] ?? null,
            'review_count' => $rating['count'] ?? 0,
            'sold_qty' => $soldQty,
        ];
    }

    /**
     * @return array<int, string> category id => store-scoped name (root/default categories excluded)
     */
    private function categoryNames(): array
    {
        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStoreId((int) $this->storeManager->getStore()->getId());
            $collection->addAttributeToSelect('name');
            $collection->addFieldToFilter('level', ['gteq' => 2]);
            $names = [];
            $paths = [];
            foreach ($collection as $category) {
                $name = trim((string) $category->getName());
                if ($name !== '') {
                    $names[(int) $category->getId()] = $name;
                    $paths[(int) $category->getId()] = (string) $category->getPath();
                }
            }
            // Products are usually assigned to leaf categories only ("Tees"), but
            // shoppers search by the ancestors ("men", "tops"), so each entry is
            // the full breadcrumb: "Men Tops Tees".
            $breadcrumbs = [];
            foreach ($paths as $id => $path) {
                $parts = [];
                foreach (explode('/', $path) as $ancestorId) {
                    if (isset($names[(int) $ancestorId])) {
                        $parts[] = $names[(int) $ancestorId];
                    }
                }
                $breadcrumbs[$id] = implode(' ', array_unique($parts));
            }
            return $breadcrumbs;
        } catch (\Throwable $e) {
            $this->logger->warning('Horizon: category names unavailable for search text: ' . $e->getMessage());
            return [];
        }
    }
}
