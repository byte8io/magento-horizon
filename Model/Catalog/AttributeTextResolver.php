<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Catalog;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves the human-readable values of a product's attributes for the AI
 * index and the product detail payload: a short list of common ones (colour,
 * size, brand…) plus everything the merchant shows in the product page's
 * "More Information" table ("Visible on Catalog Pages on Storefront"), so the
 * assistant never knows less than the page it links to.
 *
 * Every lookup is guarded: the attribute set differs per store (the sample
 * data ships `size`, `material` and `pattern`, most real catalogues don't),
 * so a missing code must yield nothing rather than a fatal error. The
 * previous implementation called `Product::getAttributeText()` directly,
 * which dereferences the attribute without checking it exists and took the
 * whole reindex down with "Call to a member function getSource() on false".
 */
class AttributeTextResolver
{
    /** Attribute codes worth surfacing to a shopper, when the catalogue has them. */
    private const CODES = ['color', 'size', 'manufacturer', 'brand', 'material', 'pattern'];

    /** Input types whose value reads as a short piece of text. */
    private const INPUTS = ['select', 'multiselect', 'boolean', 'text'];

    /** A catalogue with hundreds of storefront-visible attributes is cut off here. */
    private const MAX_CODES = 60;

    private const MAX_TEXT_LENGTH = 255;

    /** @var array<string, AbstractAttribute|false> */
    private array $attributeCache = [];

    /** @var string[]|null */
    private ?array $codes = null;

    public function __construct(
        private readonly ProductResource $productResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    /**
     * Attribute label in the current store view. Attribute models are shared
     * for the whole process and keep the label of the view they were first
     * loaded in, so the per-view labels are read directly.
     */
    public function storeLabel(string $code): string
    {
        $attribute = $this->productResource->getAttribute($code);
        if (!$attribute instanceof AbstractAttribute || !$attribute->getId()) {
            return '';
        }
        try {
            $labels = (array) $attribute->getStoreLabels();
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            return (string) $attribute->getDefaultFrontendLabel();
        }
        $label = trim((string) ($labels[$storeId] ?? ''));

        return $label !== '' ? $label : (string) $attribute->getDefaultFrontendLabel();
    }

    /**
     * The codes that exist on this catalogue, for `addAttributeToSelect()`. Passing a code the entity does not have makes
     * the EAV collection throw, and a code that is not selected is never
     * loaded onto the product, so this has to be decided up front.
     *
     * @return string[]
     */
    public function selectableCodes(): array
    {
        return array_values(array_filter($this->codes(), fn (string $code): bool => $this->attribute($code) !== false));
    }

    /**
     * The fixed list first, then the attributes shown in the product page's
     * "More Information" table.
     *
     * @return string[]
     */
    private function codes(): array
    {
        if ($this->codes === null) {
            $codes = self::CODES;
            try {
                $visible = $this->attributeCollectionFactory->create()
                    ->addFieldToFilter('is_visible_on_front', 1)
                    ->addFieldToFilter('frontend_input', ['in' => self::INPUTS])
                    ->setOrder('attribute_code', 'ASC')
                    ->getColumnValues('attribute_code');
                $codes = array_merge($codes, array_map('strval', $visible));
            } catch (\Throwable) {
                // The fixed list still works without the catalogue's own.
            }
            $this->codes = array_slice(array_values(array_unique($codes)), 0, self::MAX_CODES);
        }

        return $this->codes;
    }

    /**
     * @return array<int, array{code: string, label: string, value: string}>
     */
    public function resolve(Product $product): array
    {
        $result = [];
        foreach ($this->codes() as $code) {
            $attribute = $this->attribute($code);
            if ($attribute === false) {
                continue;
            }

            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }

            $text = $attribute->getFrontendInput() === 'text'
                ? $this->plainText($raw)
                : $this->optionText($attribute, $raw);
            if ($text === '') {
                continue;
            }

            $label = $this->storeLabel($code);
            $result[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
                'value' => $text,
            ];
        }

        return $result;
    }

    /**
     * The attribute when it exists on this entity type and is an option-based
     * input (select / multiselect / boolean) or a single line of text; false
     * otherwise.
     *
     * @return AbstractAttribute|false
     */
    private function attribute(string $code): AbstractAttribute|false
    {
        if (!array_key_exists($code, $this->attributeCache)) {
            $attribute = $this->productResource->getAttribute($code);
            $usable = $attribute instanceof AbstractAttribute
                && $attribute->getId()
                && in_array($attribute->getFrontendInput(), self::INPUTS, true);
            $this->attributeCache[$code] = $usable ? $attribute : false;
        }

        return $this->attributeCache[$code];
    }

    /**
     * @param mixed $raw a text attribute's value as stored on the product
     */
    private function plainText(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $text = trim(html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_substr($text, 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * @param mixed $raw option id(s) as stored on the product
     */
    private function optionText(AbstractAttribute $attribute, mixed $raw): string
    {
        try {
            $text = $attribute->getSource()->getOptionText($raw);
        } catch (\Throwable) {
            return '';
        }

        if (is_array($text)) {
            $text = implode(', ', array_filter(array_map('strval', $text), static fn (string $v): bool => $v !== ''));
        }

        return is_string($text) ? trim($text) : '';
    }
}
