<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Byte8\Horizon\Model\Config;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Directory\Model\AllowedCountries;
use Magento\Directory\Model\Config\Source\Country as CountrySource;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Store facts an assistant needs to answer non-catalog questions: contact
 * details, opening hours, where the store ships, which carriers and payment
 * methods are active, and the merchant's own policy pages (shipping, returns,
 * FAQ…) rendered to plain text.
 *
 * Which CMS pages are exposed is a merchant decision (Stores → Configuration →
 * Byte8 → Horizon → AI Shopping Assistant → Knowledge pages). When nothing is
 * selected we fall back to active pages whose identifier or title looks like a
 * policy page, so a fresh install still answers "what's your returns policy?".
 */
class StoreInfoService
{
    private const MAX_PAGE_CHARS = 6000;
    private const MAX_TOTAL_CHARS = 24000;
    private const MAX_LISTED_COUNTRIES = 40;

    private const AUTO_DETECT_PATTERN =
        '/shipping|deliver|return|refund|faq|customer-service|customer service|contact|terms|payment|warranty|guarantee/i';

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AllowedCountries $allowedCountries,
        private readonly CountrySource $countrySource,
        private readonly RegionFactory $regionFactory,
        private readonly ShippingConfig $shippingConfig,
        private readonly PaymentMethodListInterface $paymentMethodList,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly FilterProvider $filterProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array Shape matching the Rust StoreInfoResult.
     */
    public function getStoreInfo(): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        return [
            'store' => $this->storeDetails($storeId, (string) $store->getBaseUrl(), (string) $store->getCurrentCurrencyCode()),
            'ships_to' => $this->shipsTo($storeId),
            'shipping_methods' => $this->shippingMethods($storeId),
            'payment_methods' => $this->paymentMethods($storeId),
            'pages' => $this->pages($storeId, (string) $store->getBaseUrl()),
        ];
    }

    private function storeDetails(int $storeId, string $baseUrl, string $currency): array
    {
        $cfg = fn (string $path): ?string => $this->stringConfig($path, $storeId);

        $countryId = $cfg('general/store_information/country_id');
        $regionId = $cfg('general/store_information/region_id');
        $regionName = null;
        if ($regionId !== null && ctype_digit($regionId)) {
            $region = $this->regionFactory->create()->load((int) $regionId);
            $regionName = $region->getId() ? (string) $region->getName() : null;
        }

        return [
            'name' => $cfg('general/store_information/name'),
            'phone' => $cfg('general/store_information/phone'),
            'hours' => $cfg('general/store_information/hours'),
            'email' => $cfg('trans_email/ident_general/email'),
            'address' => [
                'street' => trim(implode("\n", array_filter([
                    $cfg('general/store_information/street_line1'),
                    $cfg('general/store_information/street_line2'),
                ]))) ?: null,
                'city' => $cfg('general/store_information/city'),
                'region' => $regionName,
                'postcode' => $cfg('general/store_information/postcode'),
                'country_id' => $countryId,
                'country' => $countryId !== null ? $this->countryName($countryId) : null,
            ],
            'vat_number' => $cfg('general/store_information/merchant_vat_number'),
            'currency' => $currency,
            'locale' => $cfg('general/locale/code'),
            'base_url' => $baseUrl,
        ];
    }

    private function shipsTo(int $storeId): array
    {
        $codes = $this->allowedCountries->getAllowedCountries(ScopeInterface::SCOPE_STORE, $storeId);
        $codes = array_values(array_unique(array_filter($codes)));
        sort($codes);

        $names = [];
        if (count($codes) <= self::MAX_LISTED_COUNTRIES) {
            foreach ($codes as $code) {
                $names[] = $this->countryName($code) ?? $code;
            }
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return [
            'count' => count($codes),
            'worldwide' => count($codes) > self::MAX_LISTED_COUNTRIES,
            'countries' => $names,
        ];
    }

    private function shippingMethods(int $storeId): array
    {
        $methods = [];
        foreach ($this->shippingConfig->getActiveCarriers($storeId) as $code => $carrier) {
            $title = $this->stringConfig(sprintf('carriers/%s/title', $code), $storeId) ?? (string) $code;
            $methods[] = ['code' => (string) $code, 'title' => $title];
        }
        return $methods;
    }

    private function paymentMethods(int $storeId): array
    {
        $methods = [];
        try {
            foreach ($this->paymentMethodList->getActiveList($storeId) as $method) {
                $title = (string) $method->getTitle();
                if ($title === '') {
                    continue;
                }
                $methods[] = ['code' => (string) $method->getCode(), 'title' => $title];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Horizon store-info: could not list payment methods: ' . $e->getMessage());
        }
        return $methods;
    }

    /**
     * @return array<int, array{identifier:string,title:string,url:string,content:string}>
     */
    private function pages(int $storeId, string $baseUrl): array
    {
        $collection = $this->pageCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('is_active', 1);

        $selected = $this->config->getKnowledgePageIdentifiers();
        if ($selected !== []) {
            $collection->addFieldToFilter('identifier', ['in' => $selected]);
        }
        $collection->setOrder('title', 'ASC');

        $pages = [];
        $budget = self::MAX_TOTAL_CHARS;
        /** @var Page $page */
        foreach ($collection as $page) {
            $identifier = (string) $page->getIdentifier();
            $title = (string) $page->getTitle();
            if ($selected === [] && !$this->looksLikePolicyPage($identifier, $title)) {
                continue;
            }

            $text = $this->toPlainText((string) $page->getContent());
            if ($text === '') {
                continue;
            }
            $text = mb_substr($text, 0, min(self::MAX_PAGE_CHARS, $budget));
            $budget -= mb_strlen($text);

            $pages[] = [
                'identifier' => $identifier,
                'title' => $title,
                'url' => rtrim($baseUrl, '/') . '/' . $identifier,
                'content' => $text,
            ];

            if ($budget <= 0) {
                break;
            }
        }

        return $pages;
    }

    private function looksLikePolicyPage(string $identifier, string $title): bool
    {
        return preg_match(self::AUTO_DETECT_PATTERN, $identifier . ' ' . $title) === 1;
    }

    private function toPlainText(string $html): string
    {
        try {
            $html = $this->filterProvider->getPageFilter()->filter($html);
        } catch (\Throwable $e) {
            // Directive rendering is best-effort; strip the raw markup instead.
            $this->logger->debug('Horizon store-info: CMS filter failed: ' . $e->getMessage());
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|section|article|blockquote)>|<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;

        return trim($text);
    }

    private function countryName(string $code): ?string
    {
        static $byCode = null;
        if ($byCode === null) {
            $byCode = [];
            foreach ($this->countrySource->toOptionArray() as $option) {
                if (!empty($option['value'])) {
                    $byCode[$option['value']] = (string) $option['label'];
                }
            }
        }
        return $byCode[$code] ?? null;
    }

    private function stringConfig(string $path, int $storeId): ?string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
