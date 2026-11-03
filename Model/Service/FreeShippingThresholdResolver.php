<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * "From what basket value is delivery free?"
 *
 * Magento keeps that number in three different places, depending on how the
 * merchant set the shop up:
 *
 *  - the Free Shipping method's own minimum order amount;
 *  - a core carrier's "free shipping with minimum order amount" (DHL, UPS,
 *    FedEx, USPS all have it);
 *  - table rates, where it is simply the first row priced at zero.
 *
 * Third-party carriers often take their prices from a core method: DHL Paket
 * by Netresearch "emulates" flat rate or table rates, so the delegate is
 * resolved as well. The lowest threshold among the active carriers wins — a
 * shopper only needs one free option.
 *
 * Reads configuration and the table-rate rows; nothing is written.
 */
class FreeShippingThresholdResolver
{
    private const XML_PATH_FREESHIPPING_ACTIVE = 'carriers/freeshipping/active';
    private const XML_PATH_FREESHIPPING_SUBTOTAL = 'carriers/freeshipping/free_shipping_subtotal';
    private const XML_PATH_TABLERATE_CONDITION = 'carriers/tablerate/condition_name';

    /**
     * Carriers that take their rates from another (core) carrier, keyed by
     * carrier code; the config path holds the delegate's code.
     */
    private const EMULATION_PATHS = [
        'dhlpaket' => 'dhlshippingsolutions/dhlpaket/checkout_settings/emulated_carrier',
    ];

    /** Table-rate conditions where the row value is a basket amount. */
    private const VALUE_CONDITIONS = ['package_value', 'package_value_with_discount'];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ShippingConfig $shippingConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{amount: float, carrier: string, source: string}|null
     *   `source` is `free_shipping_method`, `carrier` or `table_rate`;
     *   `carrier` is the code the shopper sees at checkout.
     */
    public function resolve(int $storeId, ?string $countryId = null): ?array
    {
        $candidates = [];

        if ($this->scopeConfig->isSetFlag(self::XML_PATH_FREESHIPPING_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId)) {
            $amount = $this->floatConfig(self::XML_PATH_FREESHIPPING_SUBTOTAL, $storeId);
            if ($amount !== null) {
                $candidates[] = ['amount' => $amount, 'carrier' => 'freeshipping', 'source' => 'free_shipping_method'];
            }
        }

        $active = array_map('strval', array_keys($this->shippingConfig->getActiveCarriers($storeId)));
        foreach ($active as $code) {
            $this->collect($candidates, $code, $code, $storeId, $countryId);

            $emulationPath = self::EMULATION_PATHS[$code] ?? null;
            if ($emulationPath === null) {
                continue;
            }
            $delegate = trim((string) $this->scopeConfig->getValue($emulationPath, ScopeInterface::SCOPE_STORE, $storeId));
            if ($delegate !== '' && $delegate !== $code) {
                $this->collect($candidates, $delegate, $code, $storeId, $countryId);
            }
        }

        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => $a['amount'] <=> $b['amount']);
        return $candidates[0];
    }

    /**
     * @param array<int, array{amount: float, carrier: string, source: string}> $candidates
     */
    private function collect(array &$candidates, string $code, string $reportAs, int $storeId, ?string $countryId): void
    {
        if ($code === 'tablerate') {
            $amount = $this->tableRateThreshold($storeId, $countryId);
            if ($amount !== null) {
                $candidates[] = ['amount' => $amount, 'carrier' => $reportAs, 'source' => 'table_rate'];
            }
            return;
        }
        if ($code === 'freeshipping' || $code === 'flatrate') {
            return;
        }
        if (!$this->scopeConfig->isSetFlag(sprintf('carriers/%s/free_shipping_enable', $code), ScopeInterface::SCOPE_STORE, $storeId)) {
            return;
        }
        $amount = $this->floatConfig(sprintf('carriers/%s/free_shipping_subtotal', $code), $storeId);
        if ($amount !== null) {
            $candidates[] = ['amount' => $amount, 'carrier' => $reportAs, 'source' => 'carrier'];
        }
    }

    /**
     * The lowest basket value with a zero-priced table-rate row for the
     * destination (country rows beat the "any country" rows when both exist).
     * Null when rates are weight- or quantity-based or nothing is free.
     */
    private function tableRateThreshold(int $storeId, ?string $countryId): ?float
    {
        $condition = (string) $this->scopeConfig->getValue(self::XML_PATH_TABLERATE_CONDITION, ScopeInterface::SCOPE_STORE, $storeId);
        if (!in_array($condition, self::VALUE_CONDITIONS, true)) {
            return null;
        }

        try {
            $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('shipping_tablerate'),
                    ['dest_country_id', 'condition_value', 'price']
                )
                ->where('website_id = ?', $websiteId)
                ->where('condition_name = ?', $condition);
            if ($countryId !== null && $countryId !== '') {
                $select->where('dest_country_id IN (?)', [strtoupper($countryId), '0']);
            }
            $rows = $connection->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->warning('Horizon: could not read table rates: ' . $e->getMessage());
            return null;
        }

        return self::thresholdFromRows($rows, $countryId);
    }

    /**
     * Pure part of the table-rate lookup, kept separate so it can be reasoned
     * about without a database.
     *
     * @param array<int, array{dest_country_id: string, condition_value: string|float, price: string|float}> $rows
     */
    public static function thresholdFromRows(array $rows, ?string $countryId): ?float
    {
        if ($countryId !== null && $countryId !== '') {
            $country = strtoupper($countryId);
            $specific = array_filter($rows, static fn (array $r): bool => strtoupper((string) $r['dest_country_id']) === $country);
            $rows = $specific !== []
                ? $specific
                : array_filter($rows, static fn (array $r): bool => (string) $r['dest_country_id'] === '0');
        }

        $threshold = null;
        foreach ($rows as $row) {
            if ((float) $row['price'] > 0.0) {
                continue;
            }
            $value = (float) $row['condition_value'];
            if ($threshold === null || $value < $threshold) {
                $threshold = $value;
            }
        }
        return $threshold;
    }

    private function floatConfig(string $path, int $storeId): ?float
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
