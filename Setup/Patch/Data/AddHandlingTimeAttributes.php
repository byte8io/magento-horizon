<?php

declare(strict_types=1);

namespace Byte8\Horizon\Setup\Patch\Data;

use Byte8\Horizon\Model\Service\HandlingTimeResolver;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Product attributes for a handling time that differs from the store's:
 * made-to-order items, items sent by a supplier. Empty on every product
 * until a merchant fills them in, so nothing changes on upgrade.
 */
class AddHandlingTimeAttributes implements DataPatchInterface
{
    private const GROUP = 'Delivery Estimates';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $attributes = [
            HandlingTimeResolver::ATTRIBUTE_MIN => [
                'label' => 'Handling Time, From (days)',
                'note' => 'Days until this product is dispatched, when that differs from the store\'s handling time '
                    . '(Byte8 Horizon, Delivery Estimates). Leave empty to use the store\'s.',
                'sort_order' => 10,
            ],
            HandlingTimeResolver::ATTRIBUTE_MAX => [
                'label' => 'Handling Time, Up To (days)',
                'note' => 'Long end of the range, e.g. 3 above and 5 here for "3 to 5 days". '
                    . 'Leave empty for a fixed number of days.',
                'sort_order' => 20,
            ],
        ];

        foreach ($attributes as $code => $attribute) {
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type' => 'int',
                'input' => 'text',
                'label' => $attribute['label'],
                'note' => $attribute['note'],
                'frontend_class' => 'validate-digits',
                'group' => self::GROUP,
                'sort_order' => $attribute['sort_order'],
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'required' => false,
                'user_defined' => false,
                'visible' => true,
                'visible_on_front' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'used_in_product_listing' => false,
                'is_used_in_grid' => true,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => true,
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
