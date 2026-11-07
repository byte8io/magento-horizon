<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service\Write;

use Byte8\Horizon\Controller\Api\Admin\WriteException;
use Byte8\Horizon\Model\Config;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Create or update one product from an agent's request body. Shared by the
 * single and the bulk endpoint so both apply the same rules:
 *
 * - only well-known fields plus the merchant's attribute allowlist are written;
 * - select/multiselect values may be sent as option labels (resolved to ids);
 * - price moves above the merchant's cap are refused;
 * - new products are created DISABLED so a person enables them;
 * - the returned change set lists every field that changes, before and after.
 */
class ProductWriter
{
    /** Fields every store may write, keyed by request name → EAV code. */
    private const CORE_FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'short_description' => 'short_description',
        'price' => 'price',
        'special_price' => 'special_price',
        'special_from_date' => 'special_from_date',
        'special_to_date' => 'special_to_date',
        'weight' => 'weight',
        'url_key' => 'url_key',
        'meta_title' => 'meta_title',
        'meta_keyword' => 'meta_keyword',
        'meta_description' => 'meta_description',
    ];

    private const PRICE_FIELDS = ['price', 'special_price'];

    private const VISIBILITY = [
        'not_visible' => Visibility::VISIBILITY_NOT_VISIBLE,
        'catalog' => Visibility::VISIBILITY_IN_CATALOG,
        'search' => Visibility::VISIBILITY_IN_SEARCH,
        'catalog_search' => Visibility::VISIBILITY_BOTH,
    ];

    private const CREATABLE_TYPES = [Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL];

    public function __construct(
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFactory $productFactory,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockWriter $stockWriter
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> The endpoint response payload.
     */
    public function write(array $body, bool $dryRun, bool $allowCreate): array
    {
        $sku = trim((string) ($body['sku'] ?? ''));
        if ($sku === '') {
            throw new WriteException('"sku" is required', 'VALIDATION', 400);
        }
        $product = $this->load($sku);
        $isNew = $product === null;
        if ($isNew) {
            if (!$allowCreate) {
                throw new WriteException(sprintf('No product with SKU "%s"', $sku), 'NOT_FOUND', 404);
            }
            $product = $this->newProduct($sku, $body);
        }
        /** @var Product $product */
        $changes = [];
        if ($isNew) {
            $changes[] = $this->change('sku', null, $sku);
            $changes[] = $this->change('type_id', null, $product->getTypeId());
            $changes[] = $this->change('attribute_set', null, $this->attributeSetName((int) $product->getAttributeSetId()));
        }

        foreach (self::CORE_FIELDS as $field => $code) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $after = $this->scalar($field, $body[$field]);
            if ($after === null && $isNew) {
                continue;
            }
            $before = $isNew ? null : $this->normalise($product->getData($code));
            if (in_array($field, self::PRICE_FIELDS, true)) {
                $after = $this->price($field, $after, $before);
            }
            if ($isNew || $before !== $after) {
                $changes[] = $this->change($field, $before, $after);
                $product->setData($code, $after);
            }
        }
        if ($isNew && $product->getData('name') === null) {
            throw new WriteException('A new product needs a "name"', 'VALIDATION', 400);
        }
        if ($isNew && $product->getData('price') === null && $product->getTypeId() !== Type::TYPE_VIRTUAL) {
            throw new WriteException('A new product needs a "price"', 'VALIDATION', 400);
        }

        if (array_key_exists('status', $body)) {
            $after = $this->status($body['status']);
            $before = $isNew ? null : ((int) $product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled');
            if ($isNew || $before !== $after) {
                $changes[] = $this->change('status', $before, $after);
                $product->setStatus($after === 'enabled' ? Status::STATUS_ENABLED : Status::STATUS_DISABLED);
            }
        } elseif ($isNew) {
            $changes[] = $this->change('status', null, 'disabled');
        }
        if (array_key_exists('visibility', $body)) {
            $after = $this->visibility($body['visibility']);
            $before = $isNew ? null : (int) $product->getVisibility();
            if ($isNew || $before !== $after) {
                $changes[] = $this->change('visibility', $before === null ? null : $this->visibilityName($before), $this->visibilityName($after));
                $product->setVisibility($after);
            }
        } elseif ($isNew) {
            $changes[] = $this->change('visibility', null, $this->visibilityName((int) $product->getVisibility()));
        }

        if (array_key_exists('attributes', $body)) {
            $changes = array_merge($changes, $this->customAttributes($product, $body['attributes'], $isNew));
        }

        if (array_key_exists('website_ids', $body)) {
            $after = $this->intList('website_ids', $body['website_ids']);
            $before = $isNew ? null : $this->sortedInts($product->getWebsiteIds());
            if ($isNew || $before !== $after) {
                $changes[] = $this->change('website_ids', $before, $after);
                $product->setWebsiteIds($after);
            }
        } elseif ($isNew) {
            $changes[] = $this->change('website_ids', null, $this->sortedInts($product->getWebsiteIds()));
        }

        $categoryIds = null;
        if (array_key_exists('category_ids', $body)) {
            $after = $this->intList('category_ids', $body['category_ids'], true);
            $before = $isNew ? null : $this->sortedInts($product->getCategoryIds());
            if ($isNew || $before !== $after) {
                $changes[] = $this->change('category_ids', $before, $after);
                $categoryIds = $after;
            }
        }

        $stock = null;
        if (array_key_exists('stock', $body)) {
            $stock = $this->stockWriter->plan($sku, $body['stock'], $isNew);
            $changes = array_merge($changes, $stock['changes']);
        }

        $saved = false;
        if (!$dryRun && $changes !== []) {
            $product = $this->productRepository->save($product);
            if ($categoryIds !== null) {
                $this->categoryLinkManagement->assignProductToCategories($sku, $categoryIds);
            }
            if ($stock !== null) {
                $this->stockWriter->apply($sku, $stock);
            }
            $saved = true;
        }

        return [
            'action' => $isNew ? 'create' : 'update',
            'dry_run' => $dryRun,
            'saved' => $saved,
            'noop' => $changes === [],
            'entity_type' => 'product',
            'entity_id' => $product->getId() !== null ? (string) $product->getId() : null,
            'sku' => $sku,
            'name' => (string) $product->getName(),
            'type_id' => (string) $product->getTypeId(),
            'status' => (int) $product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
            'url' => $this->url($product),
            'changes' => $changes,
        ];
    }

    private function load(string $sku): ?Product
    {
        try {
            /** @var Product $product */
            $product = $this->productRepository->get($sku, true, 0, true);
            return $product;
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function newProduct(string $sku, array $body): Product
    {
        $type = strtolower(trim((string) ($body['type'] ?? Type::TYPE_SIMPLE)));
        if (!in_array($type, self::CREATABLE_TYPES, true)) {
            throw new WriteException(
                sprintf('Only %s products can be created through Horizon', implode(' and ', self::CREATABLE_TYPES)),
                'VALIDATION',
                400
            );
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/', $sku) !== 1) {
            throw new WriteException('"sku" may contain letters, digits, ".", "_", "-" and "/" (max 64 chars)', 'VALIDATION', 400);
        }
        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setSku($sku);
        $product->setTypeId($type);
        $product->setAttributeSetId($this->attributeSetId((string) ($body['attribute_set'] ?? 'Default')));
        $product->setStatus(Status::STATUS_DISABLED);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setStoreId(0);
        $product->setWebsiteIds([(int) $this->storeManager->getStore()->getWebsiteId()]);
        return $product;
    }

    private function attributeSetId(string $name): int
    {
        $name = trim($name);
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_name', $name)
            ->addFilter('entity_type_id', 4)
            ->setPageSize(1)
            ->create();
        $sets = $this->attributeSetRepository->getList($criteria)->getItems();
        $set = reset($sets);
        if (!$set) {
            throw new WriteException(sprintf('No product attribute set named "%s"', $name), 'VALIDATION', 400);
        }
        return (int) $set->getAttributeSetId();
    }

    private function attributeSetName(int $id): string
    {
        try {
            return (string) $this->attributeSetRepository->get($id)->getAttributeSetName();
        } catch (NoSuchEntityException) {
            return (string) $id;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customAttributes(Product $product, mixed $attributes, bool $isNew): array
    {
        if (!is_array($attributes)) {
            throw new WriteException('"attributes" must be an object of attribute_code → value', 'VALIDATION', 400);
        }
        $allowed = $this->config->getWritableProductAttributes();
        $changes = [];
        foreach ($attributes as $code => $value) {
            $code = (string) $code;
            if (isset(self::CORE_FIELDS[$code]) || in_array($code, ['status', 'visibility', 'sku', 'type_id'], true)) {
                throw new WriteException(sprintf('Send "%s" as a top-level field, not under "attributes"', $code), 'VALIDATION', 400);
            }
            if (!in_array($code, $allowed, true)) {
                throw new WriteException(
                    sprintf(
                        'Attribute "%s" is not on this store\'s writable list (Stores → Configuration → Byte8 → Horizon → Agent Writes). Allowed: %s',
                        $code,
                        $allowed === [] ? 'none' : implode(', ', $allowed)
                    ),
                    'ATTRIBUTE_NOT_ALLOWED',
                    403
                );
            }
            $attribute = $this->attribute($code);
            [$after, $afterLabel] = $this->attributeValue($attribute, $value);
            $before = $isNew ? null : $this->normalise($product->getData($code));
            if ($isNew || $before !== $after) {
                $changes[] = $this->change(
                    $code,
                    $before === null ? null : $this->attributeLabel($attribute, $before),
                    $afterLabel
                );
                $product->setData($code, $after);
            }
        }
        return $changes;
    }

    private function attribute(string $code): AbstractAttribute
    {
        try {
            /** @var AbstractAttribute $attribute */
            $attribute = $this->attributeRepository->get(Product::ENTITY, $code);
            return $attribute;
        } catch (NoSuchEntityException) {
            throw new WriteException(sprintf('No product attribute "%s"', $code), 'VALIDATION', 400);
        }
    }

    /**
     * Resolve a request value to what EAV stores, plus a human label for the diff.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function attributeValue(AbstractAttribute $attribute, mixed $value): array
    {
        $input = (string) $attribute->getFrontendInput();
        if ($value === null) {
            return [null, null];
        }
        if ($input === 'select') {
            $id = $this->optionId($attribute, $value);
            return [(string) $id, $this->attributeLabel($attribute, (string) $id)];
        }
        if ($input === 'multiselect') {
            $values = is_array($value) ? $value : explode(',', (string) $value);
            $ids = [];
            foreach ($values as $v) {
                $ids[] = (string) $this->optionId($attribute, $v);
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
            $joined = implode(',', $ids);
            return [$joined, $this->attributeLabel($attribute, $joined)];
        }
        if ($input === 'boolean') {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                throw new WriteException(sprintf('Attribute "%s" expects true or false', $attribute->getAttributeCode()), 'VALIDATION', 400);
            }
            return [$bool ? '1' : '0', $bool];
        }
        if (!is_scalar($value)) {
            throw new WriteException(sprintf('Attribute "%s" expects a scalar value', $attribute->getAttributeCode()), 'VALIDATION', 400);
        }
        $scalar = $this->normalise($value);
        return [$scalar, $scalar];
    }

    /**
     * Options with admin and current-store labels (an agent may send either).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function options(AbstractAttribute $attribute): array
    {
        $out = [];
        $storeOptions = $attribute->getSource()->getAllOptions(false);
        $attribute->unsetData('source_model_instance');
        $attribute->setStoreId(0);
        $adminOptions = $attribute->getSource()->getAllOptions(false);
        $attribute->unsetData('source_model_instance')->unsetData('store_id');
        foreach ([$adminOptions, $storeOptions] as $set) {
            foreach ($set as $option) {
                $out[] = ['value' => (string) $option['value'], 'label' => (string) $option['label']];
            }
        }
        return $out;
    }

    private function optionId(AbstractAttribute $attribute, mixed $value): int
    {
        $wanted = trim((string) $value);
        $options = $this->options($attribute);
        foreach ($options as $option) {
            if ((string) $option['value'] === $wanted) {
                return (int) $option['value'];
            }
        }
        foreach ($options as $option) {
            if (mb_strtolower(trim((string) $option['label'])) === mb_strtolower($wanted)) {
                return (int) $option['value'];
            }
        }
        throw new WriteException(
            sprintf(
                'Attribute "%s" has no option "%s". Add it with the attribute-options endpoint first',
                $attribute->getAttributeCode(),
                $wanted
            ),
            'OPTION_NOT_FOUND',
            400
        );
    }

    private function attributeLabel(AbstractAttribute $attribute, string $stored): mixed
    {
        $input = (string) $attribute->getFrontendInput();
        if ($input !== 'select' && $input !== 'multiselect') {
            return $stored;
        }
        $labels = [];
        foreach (explode(',', $stored) as $id) {
            $labels[(string) $id] = (string) $id;
        }
        // Admin labels come first in options(); keep the first match so the
        // diff reads like the admin panel.
        $seen = [];
        foreach ($this->options($attribute) as $option) {
            if (isset($labels[$option['value']]) && !isset($seen[$option['value']])) {
                $labels[$option['value']] = $option['label'];
                $seen[$option['value']] = true;
            }
        }
        $labels = array_values($labels);
        return $input === 'select' ? ($labels[0] ?? $stored) : $labels;
    }

    private function price(string $field, ?string $after, ?string $before): ?string
    {
        if ($after === null) {
            return null;
        }
        if (!is_numeric($after) || (float) $after < 0) {
            throw new WriteException(sprintf('"%s" must be a non-negative number', $field), 'VALIDATION', 400);
        }
        $after = $this->normalise((float) $after);
        $cap = $this->config->getMaxPriceChangePercent();
        if ($cap > 0 && $before !== null && is_numeric($before) && (float) $before > 0) {
            $pct = abs((float) $after - (float) $before) / (float) $before * 100;
            if ($pct > $cap) {
                throw new WriteException(
                    sprintf(
                        '"%s" would move %.1f%% (%s → %s); this store caps price changes at %d%% per write',
                        $field,
                        $pct,
                        $before,
                        $after,
                        $cap
                    ),
                    'PRICE_CAP',
                    422
                );
            }
        }
        return $after;
    }

    private function status(mixed $value): string
    {
        $v = is_bool($value) ? ($value ? 'enabled' : 'disabled') : strtolower(trim((string) $value));
        return match ($v) {
            'enabled', '1' => 'enabled',
            'disabled', '2', '0' => 'disabled',
            default => throw new WriteException('"status" must be "enabled" or "disabled"', 'VALIDATION', 400),
        };
    }

    private function visibility(mixed $value): int
    {
        if (is_numeric($value) && in_array((int) $value, self::VISIBILITY, true)) {
            return (int) $value;
        }
        $key = strtolower(trim((string) $value));
        return self::VISIBILITY[$key]
            ?? throw new WriteException(
                '"visibility" must be one of ' . implode(', ', array_keys(self::VISIBILITY)),
                'VALIDATION',
                400
            );
    }

    private function visibilityName(int $value): string
    {
        return (string) (array_search($value, self::VISIBILITY, true) ?: $value);
    }

    private function scalar(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new WriteException(sprintf('"%s" must be a string or number', $field), 'VALIDATION', 400);
        }
        return $this->normalise($value);
    }

    /** EAV returns strings; the request may carry numbers. Compare apples to apples. */
    private function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
            $s = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
            return $s === '' ? '0' : $s;
        }
        return (string) $value;
    }

    /**
     * @return int[]
     */
    private function intList(string $field, mixed $value, bool $allowEmpty = false): array
    {
        if (!is_array($value) || (!$allowEmpty && $value === [])) {
            throw new WriteException(sprintf('"%s" must be an array of ids', $field), 'VALIDATION', 400);
        }
        return $this->sortedInts($value);
    }

    /**
     * @param mixed[] $values
     * @return int[]
     */
    private function sortedInts(array $values): array
    {
        $ints = array_values(array_unique(array_map('intval', $values)));
        sort($ints);
        return $ints;
    }

    /**
     * @return array{field: string, before: mixed, after: mixed}
     */
    private function change(string $field, mixed $before, mixed $after): array
    {
        return ['field' => $field, 'before' => $before, 'after' => $after];
    }

    private function url(Product $product): ?string
    {
        $urlKey = (string) $product->getData('url_key');
        if ($urlKey === '') {
            return null;
        }
        $store = $this->storeManager->getStore();
        $suffix = (string) $this->config->getProductUrlSuffix();
        return rtrim((string) $store->getBaseUrl(), '/') . '/' . $urlKey . $suffix;
    }
}
