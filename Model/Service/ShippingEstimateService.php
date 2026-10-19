<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Answers "do you deliver to X and what does it cost?" for one product.
 *
 * Builds a throwaway, never-persisted quote with the requested item and a
 * shipping address made of just country / postcode / region / city, then asks
 * Magento's own carriers for rates. Nothing is written: no quote row, no
 * customer, no address. The response is always HTTP 200 with a structured
 * outcome (`ships_to`, `reason`) so the AI caller can explain the result
 * rather than treating a "we don't ship there" as a service failure.
 */
class ShippingEstimateService
{
    private const XML_PATH_FREESHIPPING_ACTIVE = 'carriers/freeshipping/active';
    private const XML_PATH_FREESHIPPING_SUBTOTAL = 'carriers/freeshipping/free_shipping_subtotal';

    private const NON_SHIPPABLE_TYPES = ['virtual', 'downloadable', 'giftcard'];
    private const OPTION_TYPES = ['bundle', 'grouped'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly QuoteFactory $quoteFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly AllowedCountries $allowedCountries,
        private readonly CountryFactory $countryFactory,
        private readonly RegionFactory $regionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly DeliveryWindowResolver $deliveryWindows,
        private readonly RuleRepositoryInterface $ruleRepository
    ) {
    }

    /**
     * @return array Shape matching the Rust ShippingEstimateResult.
     */
    public function estimate(
        ?string $sku,
        float $qty,
        string $countryId,
        ?string $postcode = null,
        ?string $region = null,
        ?string $city = null
    ): array {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $countryId = strtoupper(trim($countryId));
        $qty = $qty > 0 ? $qty : 1.0;

        $country = $this->countryFactory->create()->loadByCode($countryId);
        $destination = [
            'country_id' => $countryId,
            'country' => $country->getId() ? $country->getName() : null,
            'postcode' => $postcode,
            'region' => $region,
            'city' => $city,
        ];

        $result = [
            'sku' => $sku,
            'product_name' => null,
            'shipped_sku' => null,
            'qty' => $qty,
            'destination' => $destination,
            'ships_to' => false,
            'requires_shipping' => true,
            'reason' => null,
            'message' => null,
            'currency' => (string) $store->getCurrentCurrencyCode(),
            'subtotal' => null,
            'methods' => [],
            'unavailable' => [],
            'free_shipping_from' => $this->freeShippingThreshold($storeId),
            'free_shipping_reason' => null,
            'today' => $this->deliveryWindows->today(),
            'handling_days' => $this->deliveryWindows->handlingDays(),
        ];

        if (!$country->getId()) {
            return $this->fail($result, 'unknown_country', sprintf('"%s" is not a recognised ISO country code.', $countryId));
        }

        $allowed = $this->allowedCountries->getAllowedCountries(ScopeInterface::SCOPE_STORE, $storeId);
        if (!in_array($countryId, $allowed, true)) {
            return $this->fail(
                $result,
                'country_not_served',
                sprintf('This store does not ship to %s.', $country->getName())
            );
        }

        if ($sku === null || $sku === '') {
            $result['ships_to'] = true;
            $result['reason'] = 'no_item';
            $result['message'] = sprintf(
                'The store ships to %s. Provide a product SKU to get exact delivery options and prices.',
                $country->getName()
            );
            return $result;
        }

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException) {
            return $this->fail($result, 'product_not_found', sprintf('No product found with SKU "%s".', $sku));
        }

        $result['product_name'] = $product->getName();
        $typeId = (string) $product->getTypeId();

        if (in_array($typeId, self::NON_SHIPPABLE_TYPES, true)) {
            $result['ships_to'] = true;
            $result['requires_shipping'] = false;
            $result['reason'] = 'no_shipping_required';
            $result['message'] = 'This product is delivered digitally; no shipping applies.';
            return $result;
        }

        if (in_array($typeId, self::OPTION_TYPES, true)) {
            return $this->fail(
                $result,
                'needs_options',
                'This product is a set of items; ask which specific item the shopper wants and estimate with its SKU.'
            );
        }

        $shippable = $this->resolveShippable($product);
        if ($shippable === null) {
            return $this->fail($result, 'not_available', 'No purchasable variant of this product is currently available.');
        }
        if ($shippable->getSku() !== $product->getSku()) {
            $result['shipped_sku'] = $shippable->getSku();
        }

        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($storeId);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId(0);
        $quote->setIsActive(false);

        $item = $quote->addProduct($shippable, $qty);
        if (is_string($item)) {
            return $this->fail($result, 'not_available', $item);
        }

        $address = $quote->getShippingAddress();
        $address->setCountryId($countryId);
        if ($postcode !== null) {
            $address->setPostcode($postcode);
        }
        if ($city !== null) {
            $address->setCity($city);
        }
        $this->applyRegion($address, $countryId, $region);
        $address->setCollectShippingRates(true);

        $quote->collectTotals();

        $result['subtotal'] = round((float) $address->getSubtotal(), 2);

        foreach ($address->getAllShippingRates() as $rate) {
            if ($rate->getErrorMessage()) {
                $result['unavailable'][] = [
                    'carrier_title' => (string) $rate->getCarrierTitle(),
                    'message' => (string) $rate->getErrorMessage(),
                ];
                continue;
            }
            $window = $this->deliveryWindows->transitWindow(
                (string) $rate->getCarrier(),
                (string) $rate->getMethod(),
                (string) $rate->getMethodTitle(),
                (string) $rate->getCarrierTitle()
            );
            $estimated = $window !== null
                ? $this->deliveryWindows->project('now', $result['handling_days'], $window)
                : null;
            $result['methods'][] = [
                'carrier_code' => (string) $rate->getCarrier(),
                'carrier_title' => (string) $rate->getCarrierTitle(),
                'method_code' => (string) $rate->getMethod(),
                'method_title' => (string) $rate->getMethodTitle(),
                'price' => round($this->priceCurrency->convert((float) $rate->getPrice(), $store), 2),
                'transit_min_days' => $window['min_days'] ?? null,
                'transit_max_days' => $window['max_days'] ?? null,
                'business_days' => $window['business_days'] ?? null,
                'transit_source' => $window['source'] ?? null,
                'estimated_delivery' => $estimated,
            ];
        }

        usort($result['methods'], static fn (array $a, array $b): int => $a['price'] <=> $b['price']);

        $result['free_shipping_reason'] = $this->freeShippingReason(
            $address,
            $result['methods'],
            $result['free_shipping_from'],
            (float) $result['subtotal']
        );

        if ($result['methods'] === []) {
            return $this->fail(
                $result,
                'no_methods',
                sprintf('No delivery method is available to %s for this item.', $this->describe($destination))
            );
        }

        $result['ships_to'] = true;
        return $result;
    }

    /**
     * Configurable parents can't be added to a quote without a chosen variant;
     * shipping cost rarely differs between variants, so estimate with the
     * first salable child. Simple products ship as themselves.
     */
    private function resolveShippable(ProductInterface $product): ?Product
    {
        if (!$product instanceof Product) {
            return null;
        }
        if ($product->getTypeId() !== 'configurable') {
            return $product->isSalable() ? $product : null;
        }

        $typeInstance = $product->getTypeInstance();
        if (!method_exists($typeInstance, 'getUsedProducts')) {
            return null;
        }
        /** @var Product[] $children */
        $children = $typeInstance->getUsedProducts($product);
        foreach ($children as $child) {
            if ($child->isSalable()) {
                return $child;
            }
        }
        return null;
    }

    private function applyRegion(Quote\Address $address, string $countryId, ?string $region): void
    {
        if ($region === null || $region === '') {
            return;
        }
        $model = $this->regionFactory->create()->loadByCode($region, $countryId);
        if (!$model->getId()) {
            $model = $this->regionFactory->create()->loadByName($region, $countryId);
        }
        if ($model->getId()) {
            $address->setRegionId((int) $model->getId());
            $address->setRegionCode($model->getCode());
            $address->setRegion($model->getName());
        } else {
            $address->setRegion($region);
        }
    }

    /**
     * Explains why Magento offered the free shipping method, so an AI caller
     * does not read "free from 120" next to a free quote on a 59 basket as a
     * misconfiguration. Magento grants it either because the basket reached
     * the carrier threshold or because a cart price rule (e.g. "Spend $50 or
     * more - shipping is free!") flagged the items; rule ids are left on the
     * address by the discount collector.
     *
     * @param array<int, array<string, mixed>> $methods
     * @return array{source: string, rule: string|null}|null
     */
    private function freeShippingReason(
        Quote\Address $address,
        array $methods,
        ?float $threshold,
        float $subtotal
    ): ?array {
        $offered = false;
        foreach ($methods as $method) {
            if ($method['carrier_code'] === 'freeshipping') {
                $offered = true;
                break;
            }
        }
        if (!$offered) {
            return null;
        }
        if ($threshold !== null && $subtotal >= $threshold) {
            return ['source' => 'threshold', 'rule' => null];
        }

        $ruleIds = array_filter(array_map('intval', explode(',', (string) $address->getAppliedRuleIds())));
        foreach ($ruleIds as $ruleId) {
            try {
                $rule = $this->ruleRepository->getById($ruleId);
            } catch (NoSuchEntityException) {
                continue;
            }
            $freeShipping = $rule->getSimpleFreeShipping();
            if ($freeShipping !== null && (string) $freeShipping !== RuleInterface::FREE_SHIPPING_NONE
                && (string) $freeShipping !== '0'
            ) {
                return ['source' => 'cart_rule', 'rule' => (string) $rule->getName()];
            }
        }

        return ['source' => 'other', 'rule' => null];
    }

    private function freeShippingThreshold(int $storeId): ?float
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_FREESHIPPING_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId)) {
            return null;
        }
        $value = $this->scopeConfig->getValue(self::XML_PATH_FREESHIPPING_SUBTOTAL, ScopeInterface::SCOPE_STORE, $storeId);
        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function fail(array $result, string $reason, string $message): array
    {
        $result['ships_to'] = false;
        $result['reason'] = $reason;
        $result['message'] = $message;
        return $result;
    }

    private function describe(array $destination): string
    {
        $parts = array_filter([
            $destination['city'],
            $destination['postcode'],
            $destination['country'] ?? $destination['country_id'],
        ]);
        return implode(', ', $parts);
    }
}
