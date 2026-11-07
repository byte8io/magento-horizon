<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\WriteScope;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /horizon/api/v1/admin/category — create, update or move one category.
 *
 * Body: `id` to update (or `parent_id` + `name` to create), then any of
 * `name`, `is_active`, `include_in_menu`, `description`, `url_key`,
 * `meta_title`, `meta_keywords`, `meta_description`, `display_mode`
 * (PRODUCTS|PAGE|PRODUCTS_AND_PAGE), `position`, and on update `parent_id`
 * to move the category (with its children) under another parent. New
 * categories are created DISABLED so a person publishes them. Deleting is not
 * offered: that stays a human action in admin.
 */
class CategorySave extends AbstractWriteAction
{
    private const TEXT = ['name', 'description', 'url_key', 'meta_title', 'meta_keywords', 'meta_description'];
    private const DISPLAY_MODES = [Category::DM_PRODUCT, Category::DM_PAGE, Category::DM_MIXED];

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        JsonSerializer $serializer,
        LoggerInterface $logger,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryFactory $categoryFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($request, $jsonFactory, $config, $serializer, $logger);
    }

    protected function requiredScope(): string
    {
        return WriteScope::CATALOG_WRITE;
    }

    protected function handleWrite(array $body, bool $dryRun, Json $result): Json
    {
        $id = $body['id'] ?? null;
        $isNew = $id === null || $id === '';
        $changes = [];
        $moveTo = null;

        if ($isNew) {
            $parent = $this->parent($body['parent_id'] ?? null, true);
            $name = trim((string) ($this->stringField($body, 'name') ?? ''));
            if ($name === '') {
                throw new WriteException('A new category needs a "name"', 'VALIDATION', 400);
            }
            foreach ($parent->getChildrenCategories() as $sibling) {
                if (mb_strtolower((string) $sibling->getName()) === mb_strtolower($name)) {
                    throw new WriteException(
                        sprintf('"%s" already has a child category named "%s" (id %s); send its "id" to update it', $parent->getName(), $name, $sibling->getId()),
                        'CONFLICT',
                        409
                    );
                }
            }
            /** @var Category $category */
            $category = $this->categoryFactory->create();
            $category->setStoreId(0);
            // Only the parent id: CategoryRepository derives `path` from it. Setting
            // path here leaves a stale custom_attributes snapshot that the entity
            // manager merges back at insert, saving the parent's path as our own.
            $category->setParentId((int) $parent->getId());
            $category->setIsActive(false);
            $category->setIncludeInMenu(true);
            $category->setAttributeSetId((int) $category->getDefaultAttributeSetId());
            $changes[] = ['field' => 'parent_id', 'before' => null, 'after' => (int) $parent->getId()];
        } else {
            $category = $this->loadCategory((int) $id);
            if (array_key_exists('parent_id', $body) && $body['parent_id'] !== null) {
                $parent = $this->parent($body['parent_id'], false);
                if ((int) $parent->getId() !== (int) $category->getParentId()) {
                    if ((int) $parent->getId() === (int) $category->getId() || in_array((string) $category->getId(), explode('/', (string) $parent->getPath()), true)) {
                        throw new WriteException('A category cannot be moved under itself or one of its children', 'VALIDATION', 400);
                    }
                    $changes[] = ['field' => 'parent_id', 'before' => (int) $category->getParentId(), 'after' => (int) $parent->getId()];
                    $moveTo = (int) $parent->getId();
                }
            }
        }

        foreach (self::TEXT as $field) {
            $after = $this->stringField($body, $field);
            if ($after === null) {
                continue;
            }
            if ($field === 'url_key' && preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $after) !== 1) {
                throw new WriteException('"url_key" may contain letters, digits, "-" and "_"', 'VALIDATION', 400);
            }
            $before = $isNew ? null : (string) $category->getData($field);
            if ($isNew || $before !== $after) {
                $changes[] = ['field' => $field, 'before' => $before, 'after' => $after];
                $category->setData($field, $after);
            }
        }
        foreach (['is_active', 'include_in_menu'] as $flag) {
            $after = $this->boolField($body, $flag);
            if ($after === null) {
                if ($isNew) {
                    $changes[] = ['field' => $flag, 'before' => null, 'after' => (bool) $category->getData($flag)];
                }
                continue;
            }
            $before = $isNew ? null : (bool) $category->getData($flag);
            if ($isNew || $before !== $after) {
                $changes[] = ['field' => $flag, 'before' => $before, 'after' => $after];
                $category->setData($flag, $after);
            }
        }
        if (array_key_exists('display_mode', $body) && $body['display_mode'] !== null) {
            $after = strtoupper(trim((string) $body['display_mode']));
            if (!in_array($after, self::DISPLAY_MODES, true)) {
                throw new WriteException('"display_mode" must be one of ' . implode(', ', self::DISPLAY_MODES), 'VALIDATION', 400);
            }
            $before = $isNew ? null : (string) $category->getDisplayMode();
            if ($isNew || $before !== $after) {
                $changes[] = ['field' => 'display_mode', 'before' => $before, 'after' => $after];
                $category->setDisplayMode($after);
            }
        }
        if (array_key_exists('position', $body) && $body['position'] !== null) {
            if (!is_numeric($body['position'])) {
                throw new WriteException('"position" must be a number', 'VALIDATION', 400);
            }
            $after = (int) $body['position'];
            $before = $isNew ? null : (int) $category->getPosition();
            if ($isNew || $before !== $after) {
                $changes[] = ['field' => 'position', 'before' => $before, 'after' => $after];
                $category->setPosition($after);
            }
        }

        $saved = false;
        if (!$dryRun && $changes !== []) {
            $category = $this->categoryRepository->save($category);
            if ($moveTo !== null) {
                $category->move($moveTo, null);
            }
            $saved = true;
        }

        return $result->setData([
            'action' => $isNew ? 'create' : ($moveTo !== null ? 'move' : 'update'),
            'dry_run' => $dryRun,
            'saved' => $saved,
            'noop' => $changes === [],
            'entity_type' => 'category',
            'entity_id' => $category->getId() !== null ? (string) $category->getId() : null,
            'name' => (string) $category->getName(),
            'parent_id' => (int) $category->getParentId(),
            'level' => $isNew ? (int) $category->getParentCategory()->getLevel() + 1 : (int) $category->getLevel(),
            'is_active' => (bool) $category->getIsActive(),
            'include_in_menu' => (bool) $category->getIncludeInMenu(),
            'url' => $this->url($category),
            'changes' => $changes,
        ]);
    }

    private function loadCategory(int $id): Category
    {
        try {
            /** @var Category $category */
            $category = $this->categoryRepository->get($id, 0);
        } catch (NoSuchEntityException) {
            throw new WriteException(sprintf('No category with id %d', $id), 'NOT_FOUND', 404);
        }
        if ((int) $category->getLevel() < 1) {
            throw new WriteException('The root of the tree cannot be edited', 'VALIDATION', 400);
        }
        return $category;
    }

    private function parent(mixed $parentId, bool $forCreate): Category
    {
        if ($parentId === null || $parentId === '' || !is_numeric($parentId)) {
            throw new WriteException(
                $forCreate ? 'A new category needs a numeric "parent_id" (use get_categories to find one)' : '"parent_id" must be numeric',
                'VALIDATION',
                400
            );
        }
        try {
            /** @var Category $parent */
            $parent = $this->categoryRepository->get((int) $parentId, 0);
        } catch (NoSuchEntityException) {
            throw new WriteException(sprintf('No category with id %d to use as parent', (int) $parentId), 'NOT_FOUND', 404);
        }
        if ((int) $parent->getLevel() < 1) {
            throw new WriteException('Use a root category (level 1) or deeper as the parent, not the tree root', 'VALIDATION', 400);
        }
        return $parent;
    }

    private function url(Category $category): ?string
    {
        $urlKey = (string) $category->getData('url_key');
        if ($urlKey === '' || $category->getId() === null) {
            return null;
        }
        $path = (string) ($category->getData('url_path') ?: $urlKey);
        return rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/') . '/' . $path . $this->config->getCategoryUrlSuffix();
    }
}
