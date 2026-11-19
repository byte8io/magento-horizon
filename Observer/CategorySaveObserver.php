<?php

declare(strict_types=1);

namespace Byte8\Horizon\Observer;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Webhook\Notifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CategorySaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Notifier $notifier
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabledInAnyStore()) {
            return;
        }

        $category = $observer->getEvent()->getCategory();
        if (!$category || !$category->getId()) {
            return;
        }

        $event = $category->isObjectNew()
            ? Notifier::EVENT_CATEGORY_CREATED
            : Notifier::EVENT_CATEGORY_UPDATED;
        $this->notifier->notify($event, [(int) $category->getId()]);
    }
}
