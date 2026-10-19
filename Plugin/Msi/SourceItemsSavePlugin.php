<?php

declare(strict_types=1);

namespace Byte8\Horizon\Plugin\Msi;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Indexer\Dispatcher;
use Byte8\Horizon\Model\Indexer\ProductIndexer;
use Byte8\Horizon\Model\Webhook\Notifier;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;

/**
 * After-plugin on MSI SourceItemsSave. MSI is plugin-driven (no dispatched
 * events), so this is the canonical hook for "stock changed" under MSI.
 *
 * On every save we collect the unique SKUs, refresh those products in our
 * flat AI index, and push an inventory_updated webhook to the Horizon gateway
 * with the resolved product IDs.
 */
class SourceItemsSavePlugin
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
        SourceItemsSaveInterface $subject,
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
