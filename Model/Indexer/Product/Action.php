<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Indexer\Product;

use Byte8\Horizon\Model\Indexer\ProductIndexer;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * Magento indexer action for the Horizon flat AI product index.
 *
 * Declared in etc/indexer.xml (id `byte8_horizon_product`) and etc/mview.xml.
 * All three entry points delegate to {@see ProductIndexer}, which owns the
 * row-building logic; this class only adapts it to the framework contract.
 */
class Action implements IndexerActionInterface, MviewActionInterface
{
    public const INDEXER_ID = 'byte8_horizon_product';

    public function __construct(
        private readonly ProductIndexer $productIndexer
    ) {
    }

    /**
     * Full rebuild — `indexer:reindex byte8_horizon_product` or an invalidated index.
     */
    public function executeFull(): void
    {
        $this->productIndexer->reindexAll();
    }

    /**
     * Partial rebuild for a list of product IDs (Update on Save path).
     *
     * @param int[] $ids
     */
    public function executeList(array $ids): void
    {
        $this->productIndexer->reindexByIds($this->normalizeIds($ids));
    }

    /**
     * Partial rebuild for a single product ID.
     *
     * @param int $id
     */
    public function executeRow($id): void
    {
        $this->executeList([$id]);
    }

    /**
     * Mview entry point: changelog batches drained by the indexer cron.
     *
     * @param int[] $ids
     */
    public function execute($ids): void
    {
        $this->executeList(is_array($ids) ? $ids : [$ids]);
    }

    /**
     * Cast, drop non-positive values and de-duplicate.
     *
     * @param array<int|string> $ids
     * @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }
}
