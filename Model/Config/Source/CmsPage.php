<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Config\Source;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * CMS pages keyed by identifier (the core Cms source keys by page_id, which
 * is useless to the gateway and unstable across environments). Disabled
 * pages are listed too: picked by hand they become assistant-only knowledge
 * that shoppers never see on the storefront.
 */
class CmsPage implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder('title', 'ASC');

        $options = [];
        foreach ($collection as $page) {
            $label = sprintf('%s (%s)', $page->getTitle(), $page->getIdentifier());
            if (!$page->isActive()) {
                $label .= ' — ' . __('disabled, assistant only');
            }
            $options[] = [
                'value' => (string) $page->getIdentifier(),
                'label' => $label,
            ];
        }
        return $options;
    }
}
