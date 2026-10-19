<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Byte8\Horizon\Model\Config;
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
 * never has to count days itself.
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
     * @return array{min_days:int,max_days:int,business_days:bool,source:string}|null
     */
    public function transitWindow(string $carrierCode, ?string $methodCode, string ...$titles): ?array
    {
        $businessDays = $this->config->isDeliveryBusinessDays();

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
                'business_days' => $businessDays,
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
            $parsed = $this->parseTitle($title, $businessDays);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    public function handlingDays(): int
    {
        return $this->config->getDeliveryHandlingDays();
    }

    public function isBusinessDays(): bool
    {
        return $this->config->isDeliveryBusinessDays();
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
     * @return array{earliest:string,latest:string}
     */
    public function project(string $basisDate, int $leadDays, array $window): array
    {
        $basis = $this->timezone->date(new \DateTime($basisDate, new \DateTimeZone('UTC')));
        $basis->setTime(0, 0, 0);
        $business = (bool) $window['business_days'];

        $start = $this->addDays($basis, $leadDays, $business);
        return [
            'earliest' => $this->addDays($start, (int) $window['min_days'], $business)->format('Y-m-d'),
            'latest' => $this->addDays($start, (int) $window['max_days'], $business)->format('Y-m-d'),
        ];
    }

    private function addDays(\DateTimeInterface $from, int $days, bool $businessDays): \DateTime
    {
        $date = \DateTime::createFromInterface($from);
        if ($days <= 0) {
            return $date;
        }
        if (!$businessDays) {
            return $date->modify(sprintf('+%d days', $days));
        }
        $remaining = $days;
        while ($remaining > 0) {
            $date->modify('+1 day');
            if ((int) $date->format('N') < 6) {
                $remaining--;
            }
        }
        return $date;
    }

    private function parseTitle(string $title, bool $businessDays): ?array
    {
        if ($title === '') {
            return null;
        }
        if (preg_match(self::TITLE_RANGE, $title, $m)) {
            return [
                'min_days' => (int) $m[1],
                'max_days' => max((int) $m[1], (int) $m[2]),
                'business_days' => $businessDays || !empty($m[3]),
                'source' => self::SOURCE_TITLE,
            ];
        }
        if (preg_match(self::TITLE_NEXT_DAY, $title)) {
            return ['min_days' => 1, 'max_days' => 1, 'business_days' => $businessDays, 'source' => self::SOURCE_TITLE];
        }
        if (preg_match(self::TITLE_SINGLE, $title, $m)) {
            $days = (int) $m[1];
            return [
                'min_days' => $days,
                'max_days' => $days,
                'business_days' => $businessDays || !empty($m[2]),
                'source' => self::SOURCE_TITLE,
            ];
        }
        return null;
    }
}
