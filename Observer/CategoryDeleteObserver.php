<?php

declare(strict_types=1);

namespace Byte8\Horizon\Observer;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Webhook\Notifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CategoryDeleteObserver implements ObserverInterface
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

        $this->notifier->notify(Notifier::EVENT_CATEGORY_DELETED, [(int) $category->getId()]);
    }
}
