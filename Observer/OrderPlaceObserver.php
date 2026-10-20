<?php

declare(strict_types=1);

namespace Byte8\Horizon\Observer;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Indexer\Dispatcher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Keeps `sold_qty` on the flat index current: every placed order bumps the
 * ordered products (Update on Save) or queues them (Update by Schedule).
 */
class OrderPlaceObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Dispatcher $indexerDispatcher
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $order = $observer->getEvent()->getOrder();
        if (!$order || !method_exists($order, 'getAllItems')) {
            return;
        }

        $productIds = [];
        foreach ($order->getAllItems() as $item) {
            $productId = (int) $item->getProductId();
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
        }

        if ($productIds !== []) {
            $this->indexerDispatcher->reindexIds(array_values($productIds));
        }
    }
}
