<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\DeliveryDays;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Turns "which shipping method" into "roughly when will it arrive".
 *
 * Transit windows come from admin config (Byte8 → Horizon → Delivery
 * Estimates → Transit Times, per carrier / optional method). When nothing is
 * configured we fall back to parsing the carrier or method title — merchants
 * very often name methods "Standard Delivery (3-5 days)" — and report the
 * weaker `source` so the assistant can hedge.
 *
 * All date arithmetic happens here, in the store's timezone, so the model
 * never has to count days itself. Which days count (`delivery_days`:
 * calendar, Monday–Friday or Monday–Saturday) is a store setting; a title
 * that says "business days" never counts weekends even when the store does.
 */
class DeliveryWindowResolver
{
    public const SOURCE_CONFIGURED = 'configured';
    public const SOURCE_TITLE = 'carrier_title';

    private const TITLE_RANGE = '/(\d{1,2})\s*(?:-|–|—|to)\s*(\d{1,2})\s*(business|working)?\s*(?:days?|wd)\b/iu';
    private const TITLE_SINGLE = '/\b(\d{1,2})\s*(business|working)?\s*days?\b/iu';
    private const TITLE_NEXT_DAY = '/\b(next[\s-]?day|overnight|24\s*h(ours?)?)\b/iu';

    public function __construct(
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Resolve the transit window for a carrier/method.
     *
     * @return array{min_days:int,max_days:int,business_days:bool,delivery_days:string,source:string}|null
     */
    public function transitWindow(string $carrierCode, ?string $methodCode, string ...$titles): ?array
    {
        $deliveryDays = $this->config->getDeliveryDays();

        $best = null;
        foreach ($this->config->getTransitWindows() as $row) {
            if (($row['carrier'] ?? '') !== $carrierCode) {
                continue;
            }
            $rowMethod = trim((string) ($row['method'] ?? ''));
            if ($rowMethod !== '' && $methodCode !== null && $rowMethod !== $methodCode) {
                continue;
            }
            $candidate = [
                'min_days' => max(0, (int) ($row['min_days'] ?? 0)),
                'max_days' => max(0, (int) ($row['max_days'] ?? 0)),
                'business_days' => $deliveryDays !== DeliveryDays::CALENDAR,
                'delivery_days' => $deliveryDays,
                'source' => self::SOURCE_CONFIGURED,
            ];
            // A method-specific row beats a carrier-wide row.
            if ($best === null || $rowMethod !== '') {
                $best = $candidate;
            }
            if ($rowMethod !== '') {
                break;
            }
        }
        if ($best !== null) {
            if ($best['max_days'] < $best['min_days']) {
                $best['max_days'] = $best['min_days'];
            }
            return $best;
        }

        // Titles passed by the caller first (what the shopper saw), then the
        // carrier's *current* admin titles — orders store a historical
        // description that may predate a merchant adding "(3-5 days)".
        $titles[] = (string) $this->scopeConfig->getValue(sprintf('carriers/%s/title', $carrierCode));
        $titles[] = (string) $this->scopeConfig->getValue(sprintf('carriers/%s/name', $carrierCode));
        foreach ($titles as $title) {
            $parsed = $this->parseTitle($title, $deliveryDays);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * The store's handling time, short end (the only one when it is fixed).
     */
    public function handlingDays(): int
    {
        return $this->config->getDeliveryHandlingDays();
    }

    /**
     * The store's handling time, long end.
     */
    public function handlingDaysMax(): int
    {
        return $this->config->getDeliveryHandlingDaysMax();
    }

    public function isBusinessDays(): bool
    {
        return $this->config->isDeliveryBusinessDays();
    }

    /**
     * `calendar` | `mon_fri` | `mon_sat` — which days handling and transit days count.
     */
    public function deliveryDays(): string
    {
        return $this->config->getDeliveryDays();
    }

    /**
     * `dates` | `days` — whether arrival dates are shared with the assistant.
     */
    public function estimateFormat(): string
    {
        return $this->config->getDeliveryEstimateFormat();
    }

    public function showsDates(): bool
    {
        return $this->config->showsDeliveryDates();
    }

    /**
     * `dates` | `days` for one order or quote: a product with its own, longer
     * handling time can switch it to day counts (Tell Shoppers, Slow Items).
     */
    public function estimateFormatFor(bool $extendedHandling): string
    {
        return $extendedHandling ? $this->config->getDeliverySlowItemFormat() : $this->estimateFormat();
    }

    /**
     * Today's date in the store timezone (Y-m-d).
     */
    public function today(): string
    {
        return $this->timezone->date()->format('Y-m-d');
    }

    /**
     * Project a delivery date range from a basis date.
     *
     * @param string $basisDate  Any value DateTime understands (DB timestamps are UTC).
     * @param int $leadDays      Days to add before transit starts (handling), 0 when already shipped.
     * @param int|null $leadMaxDays Long end when handling is a range; the latest date counts from it.
     * @return array{earliest:string,latest:string}
     */
    public function project(string $basisDate, int $leadDays, array $window, ?int $leadMaxDays = null): array
    {
        $basis = $this->timezone->date(new \DateTime($basisDate, new \DateTimeZone('UTC')));
        $basis->setTime(0, 0, 0);
        $mode = $this->windowDays($window);

        $start = $this->addDays($basis, $leadDays, $mode);
        $latestStart = $this->addDays($basis, max($leadDays, $leadMaxDays ?? $leadDays), $mode);
        return [
            'earliest' => $this->addDays($start, (int) $window['min_days'], $mode)->format('Y-m-d'),
            'latest' => $this->addDays($latestStart, (int) $window['max_days'], $mode)->format('Y-m-d'),
        ];
    }

    /**
     * A window built before `delivery_days` existed only carries the boolean.
     */
    private function windowDays(array $window): string
    {
        if (isset($window['delivery_days'])) {
            return DeliveryDays::normalize($window['delivery_days']);
        }
        return !empty($window['business_days']) ? DeliveryDays::MON_FRI : DeliveryDays::CALENDAR;
    }

    private function addDays(\DateTimeInterface $from, int $days, string $mode): \DateTime
    {
        $date = \DateTime::createFromInterface($from);
        if ($days <= 0) {
            return $date;
        }
        if ($mode === DeliveryDays::CALENDAR) {
            return $date->modify(sprintf('+%d days', $days));
        }
        // ISO weekday: 6 = Saturday, 7 = Sunday.
        $lastCountedDay = $mode === DeliveryDays::MON_SAT ? 6 : 5;
        $remaining = $days;
        while ($remaining > 0) {
            $date->modify('+1 day');
            if ((int) $date->format('N') <= $lastCountedDay) {
                $remaining--;
            }
        }
        return $date;
    }

    private function parseTitle(string $title, string $deliveryDays): ?array
    {
        if ($title === '') {
            return null;
        }
        if (preg_match(self::TITLE_RANGE, $title, $m)) {
            return $this->titleWindow((int) $m[1], (int) $m[2], !empty($m[3]), $deliveryDays);
        }
        if (preg_match(self::TITLE_NEXT_DAY, $title)) {
            return $this->titleWindow(1, 1, false, $deliveryDays);
        }
        if (preg_match(self::TITLE_SINGLE, $title, $m)) {
            return $this->titleWindow((int) $m[1], (int) $m[1], !empty($m[2]), $deliveryDays);
        }
        return null;
    }

    /**
     * "3-5 business days" in a title means Monday–Friday even when the store
     * counts every day; otherwise the store setting applies.
     */
    private function titleWindow(int $min, int $max, bool $saysBusiness, string $deliveryDays): array
    {
        $mode = $saysBusiness && $deliveryDays === DeliveryDays::CALENDAR ? DeliveryDays::MON_FRI : $deliveryDays;
        return [
            'min_days' => $min,
            'max_days' => max($min, $max),
            'business_days' => $mode !== DeliveryDays::CALENDAR,
            'delivery_days' => $mode,
            'source' => self::SOURCE_TITLE,
        ];
    }
}
