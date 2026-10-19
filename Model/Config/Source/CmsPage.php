<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Config\Source;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Active CMS pages keyed by identifier (the core Cms source keys by page_id,
 * which is useless to the gateway and unstable across environments).
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
        $collection->addFieldToFilter('is_active', 1)->setOrder('title', 'ASC');

        $options = [];
        foreach ($collection as $page) {
            $options[] = [
                'value' => (string) $page->getIdentifier(),
                'label' => sprintf('%s (%s)', $page->getTitle(), $page->getIdentifier()),
            ];
        }
        return $options;
    }
}
