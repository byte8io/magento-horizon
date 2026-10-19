<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Indexer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Byte8\Horizon\Model\Review\SummaryProvider;
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
        private readonly SummaryProvider $reviewSummary
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
        $collection->addAttributeToSelect([
            'name', 'sku', 'short_description', 'description', 'price', 'special_price',
            'url_key', 'image', 'visibility', 'status', 'type_id',
        ]);
        $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);

        $currency = $this->storeManager->getStore()->getBaseCurrencyCode();
        $ratings = $this->reviewSummary->forAllProducts();
        $count = 0;
        $batch = [];

        foreach ($collection as $product) {
            $batch[] = $this->buildRow($product, $currency, $ratings[(int) $product->getId()] ?? null);
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
        $collection->addAttributeToSelect([
            'name', 'sku', 'short_description', 'description', 'price', 'special_price',
            'url_key', 'image', 'visibility', 'status', 'type_id',
        ]);
        $collection->addIdFilter($productIds);
        $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);

        $currency = $this->storeManager->getStore()->getBaseCurrencyCode();
        $ratings = $this->reviewSummary->forProductIds($productIds);
        $rows = [];

        foreach ($collection as $product) {
            $rows[] = $this->buildRow($product, $currency, $ratings[(int) $product->getId()] ?? null);
        }

        if (!empty($rows)) {
            $connection->insertMultiple($table, $rows);
        }
    }

    /**
     * @param array{avg: float|null, count: int}|null $rating Approved-review summary for this product
     */
    private function buildRow(\Magento\Catalog\Model\Product $product, string $currency, ?array $rating): array
    {
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

        // Filterable attributes (color, size, manufacturer, etc.)
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

        return [
            'product_id' => (int) $product->getId(),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'short_description' => $product->getShortDescription(),
            'description' => $product->getDescription(),
            'product_type' => $product->getTypeId(),
            'price' => $product->getPrice() ? (float) $product->getPrice() : null,
            'special_price' => $product->getSpecialPrice() ? (float) $product->getSpecialPrice() : null,
            'currency_code' => $currency,
            'is_in_stock' => $isInStock ? 1 : 0,
            'qty' => $qty,
            'url_path' => $product->getUrlKey(),
            'main_image_url' => $product->getImage() && $product->getImage() !== 'no_selection'
                ? $product->getImage()
                : null,
            'category_ids_json' => !empty($categoryIds) ? json_encode(array_map('intval', $categoryIds)) : null,
            'attributes_json' => !empty($attributes) ? json_encode($attributes) : null,
            'visibility' => (int) $product->getVisibility(),
            'status' => (int) $product->getStatus(),
            'rating_avg' => $rating['avg'] ?? null,
            'review_count' => $rating['count'] ?? 0,
        ];
    }
}
