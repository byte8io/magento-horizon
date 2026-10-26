<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Catalog;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Resolves the human-readable values of a product's filterable attributes
 * (colour, size, brand…) for the AI index and the product detail payload.
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

    /** @var array<string, AbstractAttribute|false> */
    private array $attributeCache = [];

    public function __construct(
        private readonly ProductResource $productResource
    ) {
    }

    /**
     * The codes from the list that exist on this catalogue, for
     * `addAttributeToSelect()`. Passing a code the entity does not have makes
     * the EAV collection throw, and a code that is not selected is never
     * loaded onto the product, so this has to be decided up front.
     *
     * @return string[]
     */
    public function selectableCodes(): array
    {
        return array_values(array_filter(self::CODES, fn (string $code): bool => $this->attribute($code) !== false));
    }

    /**
     * @return array<int, array{code: string, label: string, value: string}>
     */
    public function resolve(Product $product): array
    {
        $result = [];
        foreach (self::CODES as $code) {
            $attribute = $this->attribute($code);
            if ($attribute === false) {
                continue;
            }

            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }

            $text = $this->optionText($attribute, $raw);
            if ($text === '') {
                continue;
            }

            $label = (string) $attribute->getStoreLabel();
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
     * input (select / multiselect / boolean); false otherwise.
     *
     * @return AbstractAttribute|false
     */
    private function attribute(string $code): AbstractAttribute|false
    {
        if (!array_key_exists($code, $this->attributeCache)) {
            $attribute = $this->productResource->getAttribute($code);
            $usable = $attribute instanceof AbstractAttribute
                && $attribute->getId()
                && in_array($attribute->getFrontendInput(), ['select', 'multiselect', 'boolean'], true);
            $this->attributeCache[$code] = $usable ? $attribute : false;
        }

        return $this->attributeCache[$code];
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
