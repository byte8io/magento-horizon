<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Corner of the viewport the assistant launcher sits in.
 */
class WidgetPosition implements OptionSourceInterface
{
    public const BOTTOM_RIGHT = 'bottom-right';
    public const BOTTOM_LEFT = 'bottom-left';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::BOTTOM_RIGHT, 'label' => __('Bottom right')],
            ['value' => self::BOTTOM_LEFT, 'label' => __('Bottom left')],
        ];
    }
}
