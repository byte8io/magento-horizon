<?php

declare(strict_types=1);

namespace Byte8\Horizon\Observer;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Indexer\Dispatcher;
use Byte8\Horizon\Model\Webhook\Notifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Keeps `rating_avg` / `review_count` on the flat index current when a
 * review is created, approved, edited or deleted (`review_save_after`,
 * `review_delete_after`). Only product reviews matter; other entity types
 * are ignored. The gateway is told via a product_updated webhook.
 */
class ReviewChangeObserver implements ObserverInterface
{
    private const PRODUCT_ENTITY_ID = 1;

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

        $review = $observer->getEvent()->getData('object') ?? $observer->getEvent()->getData('review');
        if (!$review || (int) $review->getEntityId() !== self::PRODUCT_ENTITY_ID) {
            return;
        }

        $productId = (int) $review->getEntityPkValue();
        if ($productId <= 0) {
            return;
        }

        $this->indexerDispatcher->reindexIds([$productId]);
        $this->notifier->notify(Notifier::EVENT_PRODUCT_UPDATED, [$productId]);
    }
}
