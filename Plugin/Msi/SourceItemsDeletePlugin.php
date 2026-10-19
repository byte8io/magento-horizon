<?php

declare(strict_types=1);

namespace Byte8\Horizon\Plugin\Msi;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Indexer\Dispatcher;
use Byte8\Horizon\Model\Indexer\ProductIndexer;
use Byte8\Horizon\Model\Webhook\Notifier;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsDeleteInterface;

/**
 * After-plugin on MSI SourceItemsDelete. Removing a source-item from a
 * product is still an inventory change from the gateway's perspective —
 * push inventory_updated with the affected product IDs.
 */
class SourceItemsDeletePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly ProductIndexer $productIndexer,
        private readonly Dispatcher $indexerDispatcher,
        private readonly Notifier $notifier
    ) {
    }

    /**
     * @param SourceItemInterface[] $sourceItems
     */
    public function afterExecute(
        SourceItemsDeleteInterface $subject,
        $result,
        array $sourceItems
    ) {
        if (!$this->config->isEnabled() || empty($sourceItems)) {
            return $result;
        }

        $skus = [];
        foreach ($sourceItems as $item) {
            $sku = $item->getSku();
            if ($sku !== null && $sku !== '') {
                $skus[] = $sku;
            }
        }
        if (empty($skus)) {
            return $result;
        }

        // inventory_source_item is keyed by SKU, so mview has no trigger on it;
        // the Dispatcher queues these IDs itself when the indexer is scheduled.
        $productIds = $this->productIndexer->resolveProductIds($skus);
        $this->indexerDispatcher->reindexIds($productIds, false);
        if (!empty($productIds)) {
            $this->notifier->notify(Notifier::EVENT_INVENTORY_UPDATED, $productIds);
        }

        return $result;
    }
}
