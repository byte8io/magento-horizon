<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\WriteScope;
use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Eav\Api\AttributeGroupRepositoryInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * POST /horizon/api/v1/admin/attribute — create one product attribute and
 * assign it to an attribute set.
 *
 * Body: `attribute_code`, `label`, `type` (text|textarea|dropdown|multiselect|
 * boolean|date), optional `options` (labels, for dropdown/multiselect),
 * `attribute_set` (name, default "Default"), `group` (name, default the set's
 * default group), `scope` (global|website|store, default global) and the
 * storefront flags `is_searchable`, `is_filterable`, `is_visible_on_front`,
 * `used_in_product_listing`, `is_comparable`. Attributes are never required
 * and never deleted or retyped here. An existing code is a 409.
 */
class AttributeCreate extends AbstractWriteAction
{
    private const TYPES = [
        'text' => ['frontend' => 'text', 'backend' => 'varchar'],
        'textarea' => ['frontend' => 'textarea', 'backend' => 'text'],
        'dropdown' => ['frontend' => 'select', 'backend' => 'int'],
        'multiselect' => ['frontend' => 'multiselect', 'backend' => 'text'],
        'boolean' => ['frontend' => 'boolean', 'backend' => 'int'],
        'date' => ['frontend' => 'date', 'backend' => 'datetime'],
    ];
    private const FLAGS = ['is_searchable', 'is_filterable', 'is_visible_on_front', 'used_in_product_listing', 'is_comparable'];
    private const SCOPES = [
        'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
        'website' => ScopedAttributeInterface::SCOPE_WEBSITE,
        'store' => ScopedAttributeInterface::SCOPE_STORE,
    ];

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        JsonSerializer $serializer,
        LoggerInterface $logger,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly ProductAttributeInterfaceFactory $attributeFactory,
        private readonly ProductAttributeManagementInterface $attributeManagement,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly AttributeGroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly AttributeOptionInterfaceFactory $optionFactory
    ) {
        parent::__construct($request, $jsonFactory, $config, $serializer, $logger);
    }

    protected function requiredScope(): string
    {
        return WriteScope::CATALOG_WRITE;
    }

    protected function handleWrite(array $body, bool $dryRun, Json $result): Json
    {
        $code = strtolower(trim((string) ($this->stringField($body, 'attribute_code') ?? '')));
        if (preg_match('/^[a-z][a-z0-9_]{0,29}$/', $code) !== 1) {
            throw new WriteException(
                '"attribute_code" must start with a letter and contain only lowercase letters, digits and "_" (max 30 chars)',
                'VALIDATION',
                400
            );
        }
        try {
            $this->attributeRepository->get($code);
            throw new WriteException(sprintf('Attribute "%s" already exists', $code), 'CONFLICT', 409);
        } catch (NoSuchEntityException) {
            // free to create
        }
        $label = trim((string) ($this->stringField($body, 'label') ?? ''));
        if ($label === '') {
            throw new WriteException('"label" is required', 'VALIDATION', 400);
        }
        $type = strtolower(trim((string) ($body['type'] ?? 'text')));
        if (!isset(self::TYPES[$type])) {
            throw new WriteException('"type" must be one of ' . implode(', ', array_keys(self::TYPES)), 'VALIDATION', 400);
        }
        $scopeKey = strtolower(trim((string) ($body['scope'] ?? 'global')));
        if (!isset(self::SCOPES[$scopeKey])) {
            throw new WriteException('"scope" must be global, website or store', 'VALIDATION', 400);
        }
        $options = [];
        if (array_key_exists('options', $body) && $body['options'] !== null) {
            if (!in_array($type, ['dropdown', 'multiselect'], true)) {
                throw new WriteException('"options" only apply to dropdown and multiselect attributes', 'VALIDATION', 400);
            }
            if (!is_array($body['options'])) {
                throw new WriteException('"options" must be an array of labels', 'VALIDATION', 400);
            }
            foreach ($body['options'] as $raw) {
                $l = trim((string) (is_array($raw) ? ($raw['label'] ?? '') : $raw));
                if ($l === '') {
                    throw new WriteException('Every option needs a non-empty label', 'VALIDATION', 400);
                }
                if (!in_array($l, $options, true)) {
                    $options[] = $l;
                }
            }
        }
        $flags = [];
        foreach (self::FLAGS as $flag) {
            $flags[$flag] = $this->boolField($body, $flag) ?? false;
        }
        [$setId, $setName] = $this->attributeSet((string) ($body['attribute_set'] ?? 'Default'));
        [$groupId, $groupName] = $this->group($setId, $this->stringField($body, 'group'));

        $changes = [
            ['field' => 'attribute_code', 'before' => null, 'after' => $code],
            ['field' => 'label', 'before' => null, 'after' => $label],
            ['field' => 'type', 'before' => null, 'after' => $type],
            ['field' => 'scope', 'before' => null, 'after' => $scopeKey],
            ['field' => 'attribute_set', 'before' => null, 'after' => $setName],
            ['field' => 'group', 'before' => null, 'after' => $groupName],
        ];
        if ($options !== []) {
            $changes[] = ['field' => 'options', 'before' => null, 'after' => $options];
        }
        foreach ($flags as $flag => $value) {
            if ($value) {
                $changes[] = ['field' => $flag, 'before' => null, 'after' => true];
            }
        }

        $attributeId = null;
        if (!$dryRun) {
            $attributeId = $this->create($code, $label, $type, self::SCOPES[$scopeKey], $options, $flags);
            $this->attributeManagement->assign($setId, $groupId, $code, 1000);
        }

        return $result->setData([
            'action' => 'create',
            'dry_run' => $dryRun,
            'saved' => !$dryRun,
            'noop' => false,
            'entity_type' => 'attribute',
            'entity_id' => $attributeId !== null ? (string) $attributeId : null,
            'attribute_code' => $code,
            'label' => $label,
            'type' => $type,
            'attribute_set' => $setName,
            'group' => $groupName,
            'options' => $options,
            'changes' => $changes,
        ]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function attributeSet(string $name): array
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
        return [(int) $set->getAttributeSetId(), (string) $set->getAttributeSetName()];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function group(int $setId, ?string $name): array
    {
        $set = $this->attributeSetRepository->get($setId);
        $criteria = $this->searchCriteriaBuilder->addFilter('attribute_set_id', $setId)->create();
        $groups = $this->groupRepository->getList($criteria)->getItems();
        if ($groups === []) {
            throw new WriteException('The attribute set has no groups', 'VALIDATION', 400);
        }
        if ($name === null || trim($name) === '') {
            $defaultId = (int) $set->getDefaultGroupId();
            foreach ($groups as $group) {
                if ((int) $group->getAttributeGroupId() === $defaultId) {
                    return [$defaultId, (string) $group->getAttributeGroupName()];
                }
            }
            $first = reset($groups);
            return [(int) $first->getAttributeGroupId(), (string) $first->getAttributeGroupName()];
        }
        foreach ($groups as $group) {
            if (mb_strtolower((string) $group->getAttributeGroupName()) === mb_strtolower(trim($name))) {
                return [(int) $group->getAttributeGroupId(), (string) $group->getAttributeGroupName()];
            }
        }
        throw new WriteException(
            sprintf('Attribute set "%s" has no group "%s"', $set->getAttributeSetName(), trim($name)),
            'VALIDATION',
            400
        );
    }

    /**
     * @param string[] $options
     * @param array<string, bool> $flags
     */
    private function create(string $code, string $label, string $type, int $scope, array $options, array $flags): int
    {
        $spec = self::TYPES[$type];
        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute */
        $attribute = $this->attributeFactory->create();
        $attribute->setAttributeCode($code);
        $attribute->setDefaultFrontendLabel($label);
        $attribute->setFrontendInput($spec['frontend']);
        $attribute->setBackendType($spec['backend']);
        $attribute->setScope($scope);
        $attribute->setIsUserDefined(true);
        $attribute->setIsRequired(false);
        $attribute->setIsUnique(false);
        $attribute->setIsVisible(true);
        $attribute->setIsSearchable($flags['is_searchable']);
        $attribute->setIsFilterable($flags['is_filterable'] ? 1 : 0);
        $attribute->setIsFilterableInSearch($flags['is_filterable']);
        $attribute->setIsVisibleOnFront($flags['is_visible_on_front']);
        $attribute->setUsedInProductListing($flags['used_in_product_listing']);
        $attribute->setIsComparable($flags['is_comparable']);
        $attribute->setIsHtmlAllowedOnFront($type === 'textarea');
        if ($type === 'boolean') {
            $attribute->setSourceModel(\Magento\Eav\Model\Entity\Attribute\Source\Boolean::class);
        } elseif ($type === 'multiselect') {
            $attribute->setBackendModel(\Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend::class);
        }
        if ($options !== []) {
            $models = [];
            $i = 0;
            foreach ($options as $optionLabel) {
                $option = $this->optionFactory->create();
                $option->setLabel($optionLabel);
                $option->setSortOrder(++$i);
                $models[] = $option;
            }
            $attribute->setOptions($models);
        }
        $saved = $this->attributeRepository->save($attribute);
        return (int) $saved->getAttributeId();
    }
}
