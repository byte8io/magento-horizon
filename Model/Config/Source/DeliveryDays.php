<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which days count when handling and transit days are added to a date.
 *
 * Replaces the former yes/no "Count Business Days Only" flag; its stored
 * values still resolve (0 = calendar, 1 = Monday to Friday).
 */
class DeliveryDays implements OptionSourceInterface
{
    public const CALENDAR = 'calendar';
    public const MON_FRI = 'mon_fri';
    public const MON_SAT = 'mon_sat';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MON_FRI, 'label' => __('Monday to Friday')],
            ['value' => self::MON_SAT, 'label' => __('Monday to Saturday (carrier delivers on Saturdays)')],
            ['value' => self::CALENDAR, 'label' => __('Every day')],
        ];
    }

    /**
     * Normalise a stored value, including the legacy yes/no flag.
     */
    public static function normalize(mixed $value): string
    {
        return match ((string) $value) {
            self::MON_SAT => self::MON_SAT,
            self::CALENDAR, '0', '' => self::CALENDAR,
            default => self::MON_FRI,
        };
    }
}
