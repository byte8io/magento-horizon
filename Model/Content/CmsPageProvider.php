<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Content;

use Byte8\Horizon\Api\Content\ProviderInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Magento CMS pages. Every active page of the store is searchable here; the
 * policy subset the assistant reads for delivery/returns questions is still
 * served inline by store-info and is unaffected.
 */
class CmsPageProvider implements ProviderInterface
{
    private const SCAN_LIMIT = 200;

    public function __construct(
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly HtmlText $text
    ) {
    }

    public function getType(): string
    {
        return 'page';
    }

    public function getLabel(): string
    {
        return 'Page';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function search(array $terms, int $limit, int $storeId): array
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('is_active', 1);
        if ($terms !== []) {
            $fields = [];
            $conditions = [];
            foreach ($terms as $term) {
                foreach (['title', 'content_heading', 'content'] as $field) {
                    $fields[] = $field;
                    $conditions[] = ['like' => '%' . $this->escapeLike($term) . '%'];
                }
            }
            $collection->addFieldToFilter($fields, $conditions);
        }
        $collection->setOrder('update_time', 'DESC');
        $collection->setPageSize(self::SCAN_LIMIT);

        $baseUrl = $this->baseUrl($storeId);
        $items = [];
        /** @var Page $page */
        foreach ($collection as $page) {
            $body = $this->text->toPlainText((string) $page->getContent());
            $title = (string) $page->getTitle();
            $items[] = [
                'type' => $this->getType(),
                'id' => (string) $page->getIdentifier(),
                'title' => $title,
                'url' => $baseUrl . $page->getIdentifier(),
                'excerpt' => $this->text->excerpt($body, $terms),
                'published_at' => $page->getUpdateTime() ? (string) $page->getUpdateTime() : null,
                'score' => $terms === [] ? 0 : $this->text->score($terms, $title . ' ' . $page->getContentHeading(), $body),
            ];
        }
        return $items;
    }

    public function get(string $identifier, int $storeId): ?array
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('identifier', $identifier);
        $collection->setPageSize(1);
        /** @var Page|false $page */
        $page = $collection->getFirstItem();
        if (!$page || !$page->getId()) {
            return null;
        }
        return [
            'type' => $this->getType(),
            'id' => (string) $page->getIdentifier(),
            'title' => (string) $page->getTitle(),
            'url' => $this->baseUrl($storeId) . $page->getIdentifier(),
            'content' => $this->text->toPlainText((string) $page->getContent()),
            'published_at' => $page->getUpdateTime() ? (string) $page->getUpdateTime() : null,
        ];
    }

    private function baseUrl(int $storeId): string
    {
        return rtrim((string) $this->storeManager->getStore($storeId)->getBaseUrl(), '/') . '/';
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
