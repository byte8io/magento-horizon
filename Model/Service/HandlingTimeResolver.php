<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Byte8\Horizon\Model\Config;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * How long until an order leaves the warehouse.
 *
 * The store has one handling time (Byte8 → Horizon → Delivery Estimates),
 * fixed or a range. A product can carry its own in the attributes
 * **Handling Time, From / Up To (days)** — made to order, sent by a supplier,
 * or simply always on the shelf. A parcel waits for its slowest item, so an
 * order's handling time is the longest among its lines.
 *
 * `extended` is true when a product pushes the order past the store's own
 * time; those orders are the ones a merchant may not want dated (see
 * Config::getDeliverySlowItemFormat).
 */
class HandlingTimeResolver
{
    public const ATTRIBUTE_MIN = 'byte8_handling_days_min';
    public const ATTRIBUTE_MAX = 'byte8_handling_days_max';

    public function __construct(
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * Handling time for an order, from the lines still to be delivered.
     *
     * @return array{min_days:int,max_days:int,extended:bool,slow_skus:string[]}
     */
    public function forOrder(OrderInterface $order): array
    {
        $items = [];
        $parentIds = [];
        foreach ($order->getItems() as $item) {
            $items[(int) $item->getItemId()] = $item;
            if ($item->getParentItemId()) {
                $parentIds[(int) $item->getParentItemId()] = true;
            }
        }

        $lines = [];
        foreach ($items as $itemId => $item) {
            if (isset($parentIds[$itemId]) || $item->getIsVirtual()) {
                continue; // the children of a configurable or bundle speak for it
            }
            $parent = $item->getParentItemId() ? ($items[(int) $item->getParentItemId()] ?? null) : null;
            $top = $parent ?? $item;
            $open = (float) $top->getQtyOrdered() - (float) $top->getQtyCanceled() - (float) $top->getQtyRefunded();
            if ($open <= 0.0) {
                continue;
            }
            $lines[] = [
                'product_id' => (int) $item->getProductId(),
                'parent_product_id' => $parent ? (int) $parent->getProductId() : null,
                'sku' => (string) $top->getSku(),
            ];
        }

        return $this->forLines($lines);
    }

    /**
     * Handling time for a set of lines. A line without its own time takes its
     * parent product's (a configurable set once for all variants), then the
     * store's.
     *
     * @param array<int, array{product_id:int,parent_product_id:?int,sku:string}> $lines
     * @return array{min_days:int,max_days:int,extended:bool,slow_skus:string[]}
     */
    public function forLines(array $lines): array
    {
        $storeMin = $this->config->getDeliveryHandlingDays();
        $storeMax = $this->config->getDeliveryHandlingDaysMax();
        $store = ['min_days' => $storeMin, 'max_days' => $storeMax, 'extended' => false, 'slow_skus' => []];
        if ($lines === []) {
            return $store;
        }

        $ids = [];
        foreach ($lines as $line) {
            $ids[] = $line['product_id'];
            if ($line['parent_product_id']) {
                $ids[] = $line['parent_product_id'];
            }
        }
        $own = $this->productHandling(array_values(array_unique($ids)));
        if ($own === []) {
            return $store;
        }

        $min = null;
        $max = null;
        $slowSkus = [];
        foreach ($lines as $line) {
            $handling = $own[$line['product_id']]
                ?? ($line['parent_product_id'] ? ($own[$line['parent_product_id']] ?? null) : null)
                ?? [$storeMin, $storeMax];
            $min = $min === null ? $handling[0] : max($min, $handling[0]);
            $max = $max === null ? $handling[1] : max($max, $handling[1]);
            if ($handling[1] > $storeMax) {
                $slowSkus[$line['sku']] = true;
            }
        }

        return [
            'min_days' => (int) $min,
            'max_days' => (int) $max,
            'extended' => $slowSkus !== [],
            'slow_skus' => array_map('strval', array_keys($slowSkus)),
        ];
    }

    /**
     * Products that carry their own handling time: id => [min, max]. A
     * product with only one of the two filled in has a fixed time.
     *
     * @param int[] $productIds
     * @return array<int, array{0:int,1:int}>
     */
    private function productHandling(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        try {
            $collection = $this->productCollectionFactory->create();
            // Not a storefront listing: out-of-stock products are the ones most likely to be slow.
            $collection->setFlag('has_stock_status_filter', true);
            $collection->addAttributeToSelect([self::ATTRIBUTE_MIN, self::ATTRIBUTE_MAX]);
            $collection->addIdFilter($productIds);

            $own = [];
            foreach ($collection as $product) {
                $min = $this->days($product->getData(self::ATTRIBUTE_MIN));
                $max = $this->days($product->getData(self::ATTRIBUTE_MAX));
                if ($min === null && $max === null) {
                    continue;
                }
                $min ??= $max;
                $max ??= $min;
                $own[(int) $product->getId()] = [$min, max($min, $max)];
            }
            return $own;
        } catch (\Throwable) {
            // Attributes not installed yet or the catalog is unreadable: the store's time still stands.
            return [];
        }
    }

    private function days(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }
        return max(0, (int) $value);
    }
}
