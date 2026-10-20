<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Sales;

use Magento\Framework\App\ResourceConnection;

/**
 * Units sold per product over a rolling window, straight from sales_order_item
 * so it needs no report refresh (unlike sales_bestsellers_aggregated_*).
 * Cancelled and refunded quantities are deducted; cancelled/closed orders are
 * skipped. Feeds `sold_qty` on the flat index for best-seller ranking.
 */
class SoldSummaryProvider
{
    public const WINDOW_DAYS = 365;

    private const EXCLUDED_STATES = ['canceled', 'closed'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<int, float> product_id => units sold
     */
    public function forAllProducts(): array
    {
        return $this->query(null);
    }

    /**
     * @param int[] $productIds
     * @return array<int, float> product_id => units sold
     */
    public function forProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return [];
        }
        return $this->query($productIds);
    }

    /**
     * @param int[]|null $productIds
     * @return array<int, float>
     */
    private function query(?array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $item = $this->resourceConnection->getTableName('sales_order_item');
        $order = $this->resourceConnection->getTableName('sales_order');

        if (!$connection->isTableExists($item) || !$connection->isTableExists($order)) {
            return [];
        }

        $select = $connection->select()
            ->from(['i' => $item], [
                'product_id' => 'i.product_id',
                'sold' => new \Zend_Db_Expr(
                    'SUM(i.qty_ordered - IFNULL(i.qty_canceled, 0) - IFNULL(i.qty_refunded, 0))'
                ),
            ])
            ->join(['o' => $order], 'o.entity_id = i.order_id', [])
            ->where('o.state NOT IN (?)', self::EXCLUDED_STATES)
            ->where('o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)', self::WINDOW_DAYS)
            ->where('i.product_id IS NOT NULL')
            ->group('i.product_id');

        if ($productIds !== null) {
            $select->where('i.product_id IN (?)', $productIds);
        }

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $sold = max(0.0, (float) $row['sold']);
            if ($sold > 0) {
                $out[(int) $row['product_id']] = round($sold, 4);
            }
        }
        return $out;
    }
}
