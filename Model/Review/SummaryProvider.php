<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Review;

use Magento\Framework\App\ResourceConnection;

/**
 * Aggregates approved product reviews into {avg, count} per product.
 *
 * Reads Magento_Review's tables directly (one GROUP BY, no collections) so
 * the full reindex pays a single query for the whole catalog. `avg` is on
 * the shopper-facing 1-5 scale (Magento stores votes as percentages).
 * Degrades to "no reviews" when Magento_Review is not installed.
 */
class SummaryProvider
{
    private const APPROVED = 1;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<int, array{avg: float|null, count: int}> keyed by product id
     */
    public function forAllProducts(): array
    {
        return $this->query(null);
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{avg: float|null, count: int}> keyed by product id
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
     * @return array<int, array{avg: float|null, count: int}>
     */
    private function query(?array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $review = $this->resourceConnection->getTableName('review');
        $entity = $this->resourceConnection->getTableName('review_entity');
        $vote = $this->resourceConnection->getTableName('rating_option_vote');

        if (!$connection->isTableExists($review) || !$connection->isTableExists($vote)) {
            return [];
        }

        $select = $connection->select()
            ->from(['r' => $review], [
                'product_id' => 'r.entity_pk_value',
                'review_count' => new \Zend_Db_Expr('COUNT(DISTINCT r.review_id)'),
                'rating_pct' => new \Zend_Db_Expr('AVG(v.percent)'),
            ])
            ->join(['e' => $entity], 'e.entity_id = r.entity_id AND e.entity_code = \'product\'', [])
            ->joinLeft(['v' => $vote], 'v.review_id = r.review_id', [])
            ->where('r.status_id = ?', self::APPROVED)
            ->group('r.entity_pk_value');

        if ($productIds !== null) {
            $select->where('r.entity_pk_value IN (?)', $productIds);
        }

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['product_id']] = [
                'avg' => $row['rating_pct'] !== null ? round(((float) $row['rating_pct']) / 20, 2) : null,
                'count' => (int) $row['review_count'],
            ];
        }
        return $out;
    }
}
