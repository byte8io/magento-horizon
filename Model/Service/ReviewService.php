<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Byte8\Horizon\Model\Review\SummaryProvider;
use Magento\Framework\App\ResourceConnection;

/**
 * Approved reviews for one product, newest first, with the aggregate summary.
 *
 * Shopper-safe by construction: only title, text, nickname, rating and date
 * leave this class. customer_id, store and IP data are never selected.
 */
class ReviewService
{
    private const APPROVED = 1;
    public const MAX_LIMIT = 20;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SummaryProvider $summaryProvider
    ) {
    }

    /**
     * @return array|null null when the SKU does not resolve to a product
     */
    public function getReviews(string $sku, int $limit = 5, int $page = 1): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $page = max(1, $page);

        $product = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
                ->where('sku = ?', $sku)
                ->limit(1)
        );
        if (!$product) {
            return null;
        }
        $productId = (int) $product['entity_id'];

        $name = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('byte8_horizon_product_index'), ['name'])
                ->where('product_id = ?', $productId)
        );

        $summary = $this->summaryProvider->forProductIds([$productId])[$productId] ?? ['avg' => null, 'count' => 0];

        $reviews = [];
        $review = $this->resourceConnection->getTableName('review');
        if ($connection->isTableExists($review)) {
            $select = $connection->select()
                ->from(['r' => $review], ['review_id', 'created_at'])
                ->join(
                    ['d' => $this->resourceConnection->getTableName('review_detail')],
                    'd.review_id = r.review_id',
                    ['title', 'detail', 'nickname']
                )
                ->joinLeft(
                    ['v' => $this->resourceConnection->getTableName('rating_option_vote')],
                    'v.review_id = r.review_id',
                    ['rating_pct' => new \Zend_Db_Expr('AVG(v.percent)')]
                )
                ->where('r.entity_pk_value = ?', $productId)
                ->where('r.status_id = ?', self::APPROVED)
                ->group(['r.review_id', 'r.created_at', 'd.title', 'd.detail', 'd.nickname'])
                ->order('r.created_at DESC')
                ->limit($limit, ($page - 1) * $limit);

            foreach ($connection->fetchAll($select) as $row) {
                $reviews[] = [
                    'title' => (string) $row['title'],
                    'detail' => (string) $row['detail'],
                    'nickname' => (string) $row['nickname'],
                    'rating' => $row['rating_pct'] !== null ? round(((float) $row['rating_pct']) / 20, 1) : null,
                    'created_at' => (string) $row['created_at'],
                ];
            }
        }

        return [
            'sku' => (string) $product['sku'],
            'product_name' => $name !== false ? (string) $name : null,
            'rating_avg' => $summary['avg'],
            'review_count' => $summary['count'],
            'reviews' => $reviews,
            'total_count' => $summary['count'],
            'page' => $page,
            'page_size' => $limit,
        ];
    }
}
