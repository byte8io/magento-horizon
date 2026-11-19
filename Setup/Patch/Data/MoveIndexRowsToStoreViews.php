<?php

declare(strict_types=1);

namespace Byte8\Horizon\Setup\Patch\Data;

use Byte8\Horizon\Model\Indexer\Product\Action;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The product index used to hold one row per product; it now holds one per
 * product and store view. Rows built before the upgrade are handed to the
 * default store view, so searches keep answering until the next full reindex
 * fills the other views, and the indexer is marked invalid to trigger it.
 */
class MoveIndexRowsToStoreViews implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly StoreManagerInterface $storeManager,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(): self
    {
        try {
            $default = $this->storeManager->getDefaultStoreView();
            if ($default) {
                $this->moduleDataSetup->getConnection()->update(
                    $this->moduleDataSetup->getTable('byte8_horizon_product_index'),
                    ['store_id' => (int) $default->getId()],
                    ['store_id = ?' => 0]
                );
            }
            $this->indexerRegistry->get(Action::INDEXER_ID)->invalidate();
        } catch (\Throwable $e) {
            // `indexer:reindex byte8_horizon_product` recovers
            $this->logger->warning('Horizon: could not carry the product index over: ' . $e->getMessage());
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
