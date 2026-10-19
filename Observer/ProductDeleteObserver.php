<?php

declare(strict_types=1);

namespace Byte8\Horizon\Observer;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Indexer\Dispatcher;
use Byte8\Horizon\Model\Webhook\Notifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductDeleteObserver implements ObserverInterface
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

        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        $productId = (int) $product->getId();
        // Drops the stale index row (the product no longer resolves).
        $this->indexerDispatcher->reindexIds([$productId]);
        $this->notifier->notify(Notifier::EVENT_PRODUCT_DELETED, [$productId]);
    }
}
