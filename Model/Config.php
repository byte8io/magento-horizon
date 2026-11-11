<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model;

use Byte8\Horizon\Model\Config\Source\WidgetPosition;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'byte8_horizon/general/enabled';
    private const XML_PATH_API_KEY = 'byte8_horizon/general/api_key';
    private const XML_PATH_WRITE_API_KEY = 'byte8_horizon/general/write_api_key';
    private const XML_PATH_WRITE_SCOPES = 'byte8_horizon/general/write_scopes';
    private const XML_PATH_WRITE_PRODUCT_ATTRIBUTES = 'byte8_horizon/writes/product_attributes';
    private const XML_PATH_WRITE_MAX_PRICE_CHANGE = 'byte8_horizon/writes/max_price_change_pct';
    private const XML_PATH_WRITE_MAX_BULK_PRODUCTS = 'byte8_horizon/writes/max_bulk_products';
    private const XML_PATH_WRITE_SHIP_UNPAID = 'byte8_horizon/writes/ship_unpaid';
    private const XML_PATH_WRITE_CAPTURE_ONLINE = 'byte8_horizon/writes/capture_online';
    private const XML_PATH_WRITE_CUSTOMER_ATTRIBUTES = 'byte8_horizon/writes/customer_attributes';
    private const XML_PATH_PRODUCT_URL_SUFFIX = 'catalog/seo/product_url_suffix';
    private const XML_PATH_CATEGORY_URL_SUFFIX = 'catalog/seo/category_url_suffix';
    private const XML_PATH_GATEWAY_URL = 'byte8_horizon/general/gateway_url';
    private const XML_PATH_ASSISTANT_ENABLED = 'byte8_horizon/assistant/enabled';
    private const XML_PATH_KNOWLEDGE_PAGES = 'byte8_horizon/assistant/knowledge_pages';
    private const XML_PATH_ASSISTANT_GATE_COOKIE = 'byte8_horizon/assistant/gate_cookie';
    private const XML_PATH_ASSISTANT_GATE_COOKIE_VALUE = 'byte8_horizon/assistant/gate_cookie_value';
    private const XML_PATH_ASSISTANT_GATE_EVENT = 'byte8_horizon/assistant/gate_event';
    private const XML_PATH_ASSISTANT_POSITION = 'byte8_horizon/assistant/position';
    private const XML_PATH_ASSISTANT_OFFSET_BOTTOM = 'byte8_horizon/assistant/offset_bottom';
    private const XML_PATH_ASSISTANT_OFFSET_SIDE = 'byte8_horizon/assistant/offset_side';
    public const WIDGET_DEFAULT_OFFSET = 24;
    private const WIDGET_MAX_OFFSET = 400;
    private const XML_PATH_DELIVERY_HANDLING_DAYS = 'byte8_horizon/delivery/handling_days';
    private const XML_PATH_DELIVERY_BUSINESS_DAYS = 'byte8_horizon/delivery/business_days';
    private const XML_PATH_DELIVERY_TRANSIT_WINDOWS = 'byte8_horizon/delivery/transit_windows';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
    ) {
    }

    /**
     * Days between order placement and dispatch, used when an order hasn't shipped yet.
     */
    public function getDeliveryHandlingDays(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_PATH_DELIVERY_HANDLING_DAYS));
    }

    /**
     * Whether handling + transit days are counted as business days (Mon–Fri).
     */
    public function isDeliveryBusinessDays(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DELIVERY_BUSINESS_DAYS);
    }

    /**
     * Configured transit windows: rows of {carrier, method, min_days, max_days}.
     *
     * @return array<int, array<string, string>>
     */
    public function getTransitWindows(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_DELIVERY_TRANSIT_WINDOWS);
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return array_values($raw);
        }
        try {
            $decoded = $this->json->unserialize((string) $raw);
        } catch (\Throwable) {
            return [];
        }
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isAssistantEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ASSISTANT_ENABLED);
    }

    /**
     * Cookie that must be present before the storefront widget loads (age
     * gates, consent walls). Empty = load the widget immediately.
     */
    public function getAssistantGateCookie(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ASSISTANT_GATE_COOKIE));
    }

    /**
     * Exact value the gate cookie must carry. Empty = any non-empty value.
     */
    public function getAssistantGateCookieValue(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ASSISTANT_GATE_COOKIE_VALUE));
    }

    /**
     * Optional window event the gate dispatches when the visitor passes, so
     * the widget loads at once instead of at the next cookie check.
     */
    public function getAssistantGateEvent(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ASSISTANT_GATE_EVENT));
    }

    /**
     * Viewport corner for the launcher: WidgetPosition::BOTTOM_RIGHT (default)
     * or BOTTOM_LEFT.
     */
    public function getAssistantPosition(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_ASSISTANT_POSITION);

        return $value === WidgetPosition::BOTTOM_LEFT ? WidgetPosition::BOTTOM_LEFT : WidgetPosition::BOTTOM_RIGHT;
    }

    /**
     * Distance in px between the launcher and the bottom edge (default 24),
     * so it clears a theme's own back-to-top or cookie buttons.
     */
    public function getAssistantOffsetBottom(): int
    {
        return $this->offset($this->scopeConfig->getValue(self::XML_PATH_ASSISTANT_OFFSET_BOTTOM));
    }

    /**
     * Distance in px between the launcher and the side edge (default 24).
     */
    public function getAssistantOffsetSide(): int
    {
        return $this->offset($this->scopeConfig->getValue(self::XML_PATH_ASSISTANT_OFFSET_SIDE));
    }

    /**
     * @param mixed $raw
     */
    private function offset($raw): int
    {
        if ($raw === null || trim((string) $raw) === '' || !is_numeric($raw)) {
            return self::WIDGET_DEFAULT_OFFSET;
        }

        return max(0, min(self::WIDGET_MAX_OFFSET, (int) $raw));
    }

    /**
     * CMS page identifiers the assistant may read as store knowledge
     * (shipping, returns, FAQ…). Empty = auto-detect policy-looking pages.
     *
     * @return string[]
     */
    public function getKnowledgePageIdentifiers(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_KNOWLEDGE_PAGES);
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function getApiKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY);
        if ($value) {
            return $this->encryptor->decrypt($value);
        }
        return null;
    }

    /**
     * The write key issued by the Horizon dashboard; null when the store is
     * read-only. Never used for catalog reads by the gateway.
     */
    public function getWriteApiKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_WRITE_API_KEY);
        if ($value) {
            $decrypted = trim((string) $this->encryptor->decrypt($value));
            return $decrypted !== '' ? $decrypted : null;
        }
        return null;
    }

    /**
     * @return string[] Granted scopes, e.g. ['cms:write'].
     */
    public function getWriteScopes(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_WRITE_SCOPES);
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * Attribute codes (beyond the always-writable core fields) an agent may set
     * on products through the write key. Comma or newline separated in admin.
     *
     * @return string[]
     */
    public function getWritableProductAttributes(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_WRITE_PRODUCT_ATTRIBUTES);
        $codes = preg_split('/[\s,]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $codes))));
    }

    /** Largest price or special-price move per write, in percent; 0 = no cap. */
    public function getMaxPriceChangePercent(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_WRITE_MAX_PRICE_CHANGE);
        return $value === null || $value === '' ? 50 : max(0, (int) $value);
    }

    /** Most SKUs one bulk product write may touch. */
    public function getMaxBulkProducts(): int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_WRITE_MAX_BULK_PRODUCTS);
        return $value === null || $value === '' ? 50 : max(1, (int) $value);
    }

    /** May an agent ship an order that still has an amount due? Default no. */
    public function isShipUnpaidAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_WRITE_SHIP_UNPAID);
    }

    /**
     * May an invoice created by an agent capture the payment through the
     * payment gateway (when the method supports it)? Default yes; off means
     * agents only record offline captures.
     */
    public function isOnlineCaptureAllowed(): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_WRITE_CAPTURE_ONLINE);
        return $value === null || $value === '' || (bool) $value;
    }

    /**
     * Custom customer attribute codes an agent may set. Comma or newline
     * separated in admin; empty = none.
     *
     * @return string[]
     */
    public function getWritableCustomerAttributes(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_WRITE_CUSTOMER_ATTRIBUTES);
        $codes = preg_split('/[\s,]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $codes))));
    }

    public function getProductUrlSuffix(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_PRODUCT_URL_SUFFIX, ScopeInterface::SCOPE_STORE);
    }

    public function getCategoryUrlSuffix(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_CATEGORY_URL_SUFFIX, ScopeInterface::SCOPE_STORE);
    }

    public function getGatewayUrl(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_GATEWAY_URL);
        if (!$value) {
            return null;
        }
        return rtrim((string) $value, '/');
    }
}
