<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Indexer;

use Byte8\Horizon\Model\Indexer\Product\Action;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Routes "these products changed" signals from observers and plugins to the
 * Horizon indexer, honouring the indexer mode:
 *
 * - Update on Save (realtime): reindex the IDs immediately through the framework.
 * - Update by Schedule: leave it to the indexer cron. For tables mview tracks
 *   with triggers the IDs are already in the changelog and nothing is done; for
 *   sources mview cannot subscribe to (MSI `inventory_source_item` is keyed by
 *   SKU) the IDs are appended to the changelog here so they are drained in the
 *   next cron batch like everything else.
 */
class Dispatcher
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Reindex the given products now, or defer to the changelog when scheduled.
     *
     * @param int[] $productIds
     * @param bool $trackedByMview false when the change came from a source that
     *                             has no mview subscription (see class docblock)
     */
    public function reindexIds(array $productIds, bool $trackedByMview = true): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return;
        }

        try {
            $indexer = $this->indexerRegistry->get(Action::INDEXER_ID);

            if (!$indexer->isScheduled()) {
                $indexer->reindexList($productIds);
                return;
            }

            if (!$trackedByMview) {
                $this->appendToChangelog($productIds);
            }
        } catch (\Throwable $e) {
            // Never let an index refresh break a catalog save; a full reindex recovers.
            $this->logger->warning('Horizon: partial reindex failed: ' . $e->getMessage(), [
                'product_ids' => $productIds,
            ]);
        }
    }

    /**
     * Mirrors what the mview triggers do for subscribed tables.
     *
     * @param int[] $productIds
     */
    private function appendToChangelog(array $productIds): void
    {
        $changelog = $this->indexerRegistry->get(Action::INDEXER_ID)->getView()->getChangelog();
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($changelog->getName());
        $column = $changelog->getColumnName();

        $connection->insertMultiple(
            $table,
            array_map(static fn (int $id): array => [$column => $id], $productIds)
        );
    }
}
