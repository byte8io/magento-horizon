<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Controller\Api\AbstractAction;
use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\WriteScope;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /horizon/api/v1/admin/cms-page — create or update one CMS page.
 *
 * Body: `id` or `identifier` to update an existing page (otherwise a page is
 * created and `identifier` + `title` are required), then any of `title`,
 * `content` (HTML), `content_heading`, `meta_title`, `meta_description`,
 * `meta_keywords`, `is_active`, `store_ids`. New pages are created **disabled**
 * unless `is_active` is sent explicitly, so a human publishes.
 *
 * Returns the resolved change set (field, before, after) whether or not it
 * was a dry run; the gateway shows it to the agent before asking for
 * confirmation and keeps it as the audit diff.
 */
class CmsPageSave extends AbstractWriteAction
{
    private const EDITABLE = [
        'title',
        'content',
        'content_heading',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        JsonSerializer $serializer,
        LoggerInterface $logger,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageFactory $pageFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($request, $jsonFactory, $config, $serializer, $logger);
    }

    protected function requiredScope(): string
    {
        return WriteScope::CMS_WRITE;
    }

    protected function handleWrite(array $body, bool $dryRun, Json $result): Json
    {
        $page = $this->resolvePage($body);
        $isNew = $page === null;
        if ($isNew) {
            $identifier = trim((string) ($this->stringField($body, 'identifier') ?? ''));
            if ($identifier === '' || !preg_match('/^[a-z0-9][a-z0-9_\/-]*$/i', $identifier)) {
                throw new WriteException(
                    'A new page needs an "identifier" (URL key: letters, digits, "-", "_", "/")',
                    'VALIDATION',
                    400
                );
            }
            if ($this->findByIdentifier($identifier) !== null) {
                throw new WriteException(
                    sprintf('A page with identifier "%s" already exists; send its "id" to update it', $identifier),
                    'CONFLICT',
                    409
                );
            }
            if (trim((string) ($this->stringField($body, 'title') ?? '')) === '') {
                throw new WriteException('A new page needs a "title"', 'VALIDATION', 400);
            }
            /** @var Page $page */
            $page = $this->pageFactory->create();
            $page->setIdentifier($identifier);
            $page->setPageLayout('1column');
            $page->setStores([0]);
            $page->setIsActive(false);
        }

        $changes = [];
        foreach (self::EDITABLE as $field) {
            $after = $this->stringField($body, $field);
            if ($after === null) {
                continue;
            }
            $before = (string) $page->getData($field);
            if ($before !== $after || $isNew) {
                $changes[] = ['field' => $field, 'before' => $isNew ? null : $before, 'after' => $after];
                $page->setData($field, $after);
            }
        }
        $isActive = $this->boolField($body, 'is_active');
        if ($isActive !== null && ($isNew || $isActive !== (bool) $page->getIsActive())) {
            $changes[] = ['field' => 'is_active', 'before' => $isNew ? null : (bool) $page->getIsActive(), 'after' => $isActive];
            $page->setIsActive($isActive);
        }
        if (array_key_exists('store_ids', $body)) {
            $storeIds = $this->storeIds($body['store_ids']);
            $current = array_map('intval', (array) ($page->getStoreId() ?? []));
            sort($current);
            if ($isNew || $current !== $storeIds) {
                $changes[] = ['field' => 'store_ids', 'before' => $isNew ? null : $current, 'after' => $storeIds];
                $page->setStores($storeIds);
            }
        }
        if ($isNew) {
            // Make the implicit defaults visible in the change set.
            if ($isActive === null) {
                $changes[] = ['field' => 'is_active', 'before' => null, 'after' => false];
            }
            if (!array_key_exists('store_ids', $body)) {
                $changes[] = ['field' => 'store_ids', 'before' => null, 'after' => [0]];
            }
        }

        $saved = false;
        if (!$dryRun && $changes !== []) {
            $this->pageRepository->save($page);
            $saved = true;
        }

        return $result->setData([
            'action' => $isNew ? 'create' : 'update',
            'dry_run' => $dryRun,
            'saved' => $saved,
            'noop' => $changes === [],
            'entity_type' => 'cms_page',
            'entity_id' => $page->getId() !== null ? (string) $page->getId() : null,
            'identifier' => (string) $page->getIdentifier(),
            'title' => (string) $page->getTitle(),
            'is_active' => (bool) $page->getIsActive(),
            'url' => rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/') . '/' . $page->getIdentifier(),
            'changes' => $changes,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function resolvePage(array $body): ?Page
    {
        $id = $body['id'] ?? null;
        if ($id !== null && $id !== '') {
            try {
                /** @var Page $page */
                $page = $this->pageRepository->getById((int) $id);
                return $page;
            } catch (NoSuchEntityException) {
                throw new WriteException(sprintf('No CMS page with id %s', (string) $id), 'NOT_FOUND', 404);
            }
        }
        $identifier = $this->stringField($body, 'identifier');
        if ($identifier === null) {
            return null;
        }
        // Explicit create: a page must not exist. Update-by-identifier is the
        // common agent path, so an existing identifier means update.
        return $this->findByIdentifier($identifier);
    }

    private function findByIdentifier(string $identifier): ?Page
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->addFieldToFilter(PageInterface::IDENTIFIER, $identifier);
        $collection->setPageSize(1);
        /** @var Page|false $page */
        $page = $collection->getFirstItem();
        return $page && $page->getId() ? $page : null;
    }

    /**
     * @return int[]
     */
    private function storeIds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new WriteException('"store_ids" must be a non-empty array of store view ids (0 = all)', 'VALIDATION', 400);
        }
        $ids = array_values(array_unique(array_map('intval', $value)));
        sort($ids);
        return $ids;
    }
}
