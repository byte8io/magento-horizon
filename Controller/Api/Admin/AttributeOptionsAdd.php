<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\WriteScope;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * POST /horizon/api/v1/admin/attribute-options — append option values to a
 * user-defined dropdown or multiselect product attribute.
 *
 * Body: `attribute_code`, `options: [{label, sort_order?, store_labels?: {storeId: label}}]`
 * or a plain array of label strings. Labels that already exist (case-
 * insensitive) are reported as `exists` and skipped, so a retry is harmless.
 * System attributes (visibility, status, tax class…) are refused.
 */
class AttributeOptionsAdd extends AbstractWriteAction
{
    private const MAX_OPTIONS = 100;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        JsonSerializer $serializer,
        LoggerInterface $logger,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly AttributeOptionManagementInterface $optionManagement,
        private readonly AttributeOptionInterfaceFactory $optionFactory,
        private readonly AttributeOptionLabelInterfaceFactory $labelFactory
    ) {
        parent::__construct($request, $jsonFactory, $config, $serializer, $logger);
    }

    protected function requiredScope(): string
    {
        return WriteScope::CATALOG_WRITE;
    }

    protected function handleWrite(array $body, bool $dryRun, Json $result): Json
    {
        $code = trim((string) ($this->stringField($body, 'attribute_code') ?? ''));
        if ($code === '') {
            throw new WriteException('"attribute_code" is required', 'VALIDATION', 400);
        }
        try {
            /** @var AbstractAttribute $attribute */
            $attribute = $this->attributeRepository->get($code);
        } catch (NoSuchEntityException) {
            throw new WriteException(sprintf('No product attribute "%s"', $code), 'NOT_FOUND', 404);
        }
        $input = (string) $attribute->getFrontendInput();
        if (!in_array($input, ['select', 'multiselect'], true)) {
            throw new WriteException(
                sprintf('Attribute "%s" is a %s field; only dropdown and multiselect attributes take options', $code, $input),
                'VALIDATION',
                400
            );
        }
        if (!$attribute->getIsUserDefined()) {
            throw new WriteException(sprintf('"%s" is a system attribute; its options cannot be changed here', $code), 'VALIDATION', 400);
        }

        $options = $body['options'] ?? null;
        if (!is_array($options) || $options === []) {
            throw new WriteException('"options" must be a non-empty array of labels or {label, sort_order, store_labels}', 'VALIDATION', 400);
        }
        if (count($options) > self::MAX_OPTIONS) {
            throw new WriteException(sprintf('At most %d options per call', self::MAX_OPTIONS), 'VALIDATION', 400);
        }

        // Labels differ per store view; an agent may send either the admin or
        // the storefront wording, so both count as "exists".
        $existing = [];
        foreach ([0, null] as $storeId) {
            if ($storeId !== null) {
                $attribute->setStoreId($storeId);
            }
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                $existing[mb_strtolower(trim((string) $option['label']))] = (string) $option['value'];
            }
            $attribute->unsetData('source_model_instance')->unsetData('store_id');
        }

        $out = [];
        $changes = [];
        $seen = [];
        foreach ($options as $raw) {
            $spec = is_array($raw) ? $raw : ['label' => $raw];
            $label = trim((string) ($spec['label'] ?? ''));
            if ($label === '') {
                throw new WriteException('Every option needs a non-empty "label"', 'VALIDATION', 400);
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (isset($existing[$key])) {
                $out[] = ['label' => $label, 'status' => 'exists', 'option_id' => (int) $existing[$key]];
                continue;
            }
            $sortOrder = isset($spec['sort_order']) && is_numeric($spec['sort_order']) ? (int) $spec['sort_order'] : null;
            $storeLabels = [];
            if (isset($spec['store_labels'])) {
                if (!is_array($spec['store_labels'])) {
                    throw new WriteException('"store_labels" must be an object of store view id → label', 'VALIDATION', 400);
                }
                foreach ($spec['store_labels'] as $storeId => $storeLabel) {
                    if (!is_numeric($storeId) || trim((string) $storeLabel) === '') {
                        throw new WriteException('"store_labels" keys must be store view ids and values non-empty labels', 'VALIDATION', 400);
                    }
                    $storeLabels[(int) $storeId] = trim((string) $storeLabel);
                }
            }
            $optionId = null;
            if (!$dryRun) {
                $optionId = $this->add($code, $label, $sortOrder, $storeLabels);
            }
            $out[] = ['label' => $label, 'status' => 'created', 'option_id' => $optionId];
            $changes[] = ['field' => 'option', 'before' => null, 'after' => $label];
        }

        return $result->setData([
            'action' => 'add_options',
            'dry_run' => $dryRun,
            'saved' => !$dryRun && $changes !== [],
            'noop' => $changes === [],
            'entity_type' => 'attribute',
            'entity_id' => $code,
            'attribute_code' => $code,
            'label' => (string) $attribute->getDefaultFrontendLabel(),
            'frontend_input' => $input,
            'options' => $out,
            'changes' => $changes,
        ]);
    }

    /**
     * @param array<int, string> $storeLabels
     */
    private function add(string $code, string $label, ?int $sortOrder, array $storeLabels): ?int
    {
        $option = $this->optionFactory->create();
        $option->setLabel($label);
        if ($sortOrder !== null) {
            $option->setSortOrder($sortOrder);
        }
        if ($storeLabels !== []) {
            $labels = [];
            foreach ($storeLabels as $storeId => $storeLabel) {
                $l = $this->labelFactory->create();
                $l->setStoreId($storeId);
                $l->setLabel($storeLabel);
                $labels[] = $l;
            }
            $option->setStoreLabels($labels);
        }
        try {
            $created = $this->optionManagement->add(Product::ENTITY, $code, $option);
        } catch (\Magento\Framework\Exception\InputException $e) {
            throw new WriteException($e->getMessage(), 'CONFLICT', 409);
        }
        // EAV returns "id_<n>" (2.4.x) or the bare id depending on version.
        $digits = preg_replace('/\D+/', '', (string) $created) ?? '';
        return $digits === '' ? null : (int) $digits;
    }
}
