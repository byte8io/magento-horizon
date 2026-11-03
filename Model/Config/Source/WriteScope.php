<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * What an agent holding the write key may change. Each scope guards a group
 * of `/horizon/api/v1/admin/*` endpoints; the gateway hides the matching MCP
 * tools from keys without the scope, and the module refuses the call anyway.
 */
class WriteScope implements OptionSourceInterface
{
    public const CMS_WRITE = 'cms:write';
    public const CATALOG_WRITE = 'catalog:write';
    public const SALES_WRITE = 'sales:write';

    public const ALL = [self::CMS_WRITE, self::CATALOG_WRITE, self::SALES_WRITE];

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::CMS_WRITE, 'label' => __('Content — CMS pages and blocks (create as disabled, update, publish)')],
            ['value' => self::CATALOG_WRITE, 'label' => __('Catalog — product attributes, prices, stock (not yet served by this version)')],
            ['value' => self::SALES_WRITE, 'label' => __('Sales — invoices, shipments, order comments (not yet served by this version)')],
        ];
    }
}
