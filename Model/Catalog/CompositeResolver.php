<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Catalog;

use Magento\Catalog\Model\Product;

/**
 * Composite products (configurable, grouped, bundle) carry no price of their
 * own and hide their choices (colour, size) in child products. AI callers need
 * a "from" price and the list of available options on the parent row, so this
 * resolves both once per product for the indexer and the detail service.
 */
class CompositeResolver
{
    /**
     * @return array{price: float|null, special_price: float|null, options: array<int, array{code: string, label: string, values: string[]}>}
     */
    public function resolve(Product $product): array
    {
        $own = [
            'price' => $product->getPrice() ? (float) $product->getPrice() : null,
            'special_price' => $product->getSpecialPrice() ? (float) $product->getSpecialPrice() : null,
            'options' => [],
        ];

        try {
            switch ($product->getTypeId()) {
                case 'configurable':
                    return $this->configurable($product, $own);
                case 'grouped':
                    return $this->fromChildren($product->getTypeInstance()->getAssociatedProducts($product), $own);
                case 'bundle':
                    return $this->bundle($product, $own);
                default:
                    return $own;
            }
        } catch (\Throwable) {
            return $own;
        }
    }

    private function configurable(Product $product, array $own): array
    {
        $type = $product->getTypeInstance();
        if (!method_exists($type, 'getConfigurableAttributesAsArray')) {
            return $own;
        }

        $options = [];
        foreach ($type->getConfigurableAttributesAsArray($product) as $attribute) {
            $values = [];
            foreach ($attribute['values'] ?? [] as $value) {
                $label = trim((string) ($value['label'] ?? ''));
                if ($label !== '') {
                    $values[] = $label;
                }
            }
            if ($values !== []) {
                $options[] = [
                    'code' => (string) ($attribute['attribute_code'] ?? ''),
                    'label' => (string) ($attribute['store_label'] ?? $attribute['label'] ?? $attribute['attribute_code'] ?? ''),
                    'values' => array_values(array_unique($values)),
                ];
            }
        }

        $result = $this->fromChildren($type->getUsedProducts($product), $own);
        $result['options'] = $options;
        return $result;
    }

    /**
     * @param iterable<Product> $children
     */
    private function fromChildren(iterable $children, array $own): array
    {
        $price = null;
        $special = null;
        foreach ($children as $child) {
            if (!$child->isSalable() && $price !== null) {
                continue;
            }
            $childPrice = (float) $child->getPrice();
            if ($childPrice > 0 && ($price === null || $childPrice < $price)) {
                $price = $childPrice;
            }
            $childSpecial = (float) $child->getSpecialPrice();
            if ($childSpecial > 0 && ($special === null || $childSpecial < $special)) {
                $special = $childSpecial;
            }
        }
        if ($price !== null) {
            $own['price'] = $price;
            $own['special_price'] = $special !== null && $special < $price ? $special : null;
        }
        return $own;
    }

    private function bundle(Product $product, array $own): array
    {
        $minimal = $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
        if ($minimal > 0) {
            $own['price'] = (float) $minimal;
        }
        return $own;
    }
}
