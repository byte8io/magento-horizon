<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How the assistant words a delivery estimate.
 */
class EstimateFormat implements OptionSourceInterface
{
    /** Arrival dates ("between 16 and 17 November"). */
    public const DATES = 'dates';
    /** Day counts only ("1 to 2 business days after dispatch"). */
    public const DAYS = 'days';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::DATES, 'label' => __('Arrival dates')],
            ['value' => self::DAYS, 'label' => __('Number of days only')],
        ];
    }
}
