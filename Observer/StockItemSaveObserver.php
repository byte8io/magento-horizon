<?php

declare(strict_types=1);

namespace Byte8\Horizon\Observer;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Indexer\Dispatcher;
use Byte8\Horizon\Model\Webhook\Notifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Reacts to legacy `cataloginventory_stock_item_save_after`. Refreshes the
 * flat product index for the affected product and pushes an
 * `inventory_updated` webhook.
 *
 * Native MSI changes flow through {@see \Byte8\Horizon\Plugin\Msi\SourceItemsSavePlugin}
 * and {@see \Byte8\Horizon\Plugin\Msi\SourceItemsDeletePlugin} instead —
 * MSI is plugin-driven, not event-driven.
 */
class StockItemSaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Dispatcher $indexerDispatcher,
        private readonly Notifier $notifier
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $item = $observer->getEvent()->getItem();
        if (!$item) {
            return;
        }

        $productId = (int) $item->getProductId();
        if ($productId <= 0) {
            return;
        }

        $this->indexerDispatcher->reindexIds([$productId]);
        $this->notifier->notify(Notifier::EVENT_INVENTORY_UPDATED, [$productId]);
    }
}
