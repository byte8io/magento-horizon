<?php

declare(strict_types=1);

namespace Byte8\Horizon\ViewModel;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Service\AssistantBootstrap;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * View model backing the AI Shopping Assistant widget script tag.
 *
 * Reads the widget configuration from the gateway bootstrap (cached by
 * AssistantBootstrap); the bootstrap is resolved at most once per request.
 */
class AssistantWidget implements ArgumentInterface
{
    private const DEFAULT_ASSISTANT_NAME = 'Shopping Assistant';
    private const DEFAULT_ACCENT_COLOR = '#06B6D4';

    private ?array $bootstrap = null;

    private bool $bootstrapLoaded = false;

    public function __construct(
        private readonly Config $config,
        private readonly AssistantBootstrap $assistantBootstrap,
        private readonly LocaleResolver $localeResolver
    ) {
    }

    public function isActive(): bool
    {
        return $this->config->isEnabled()
            && $this->config->isAssistantEnabled()
            && $this->getBootstrap() !== null;
    }

    public function getScriptUrl(): string
    {
        $value = $this->getBootstrap()['widget_url'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function getPublishableKey(): string
    {
        $value = $this->getBootstrap()['publishable_key'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function getAssistantName(): string
    {
        $value = $this->getBootstrap()['assistant_name'] ?? null;

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_ASSISTANT_NAME;
    }

    /**
     * Store view locale as a BCP 47 tag (de_DE → de-DE). The widget picks its
     * own copy (greeting, placeholder, buttons, notices) from this; the
     * assistant's replies follow the shopper's language regardless.
     */
    public function getLanguage(): string
    {
        return str_replace('_', '-', $this->localeResolver->getLocale());
    }

    public function getAccentColor(): string
    {
        $value = $this->getBootstrap()['accent_color'] ?? null;

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_ACCENT_COLOR;
    }

    /**
     * Merchant-written first message, set in the Horizon dashboard. Empty
     * means the widget uses its built-in greeting in the store view language.
     */
    public function getWelcomeMessage(): string
    {
        $value = $this->getBootstrap()['welcome_message'] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Cookie the widget waits for before loading (admin setting). Empty =
     * the plain script tag, loaded on every page.
     */
    public function getGateCookie(): string
    {
        return $this->config->getAssistantGateCookie();
    }

    public function getGateCookieValue(): string
    {
        return $this->config->getAssistantGateCookieValue();
    }

    public function getGateEvent(): string
    {
        return $this->config->getAssistantGateEvent();
    }

    /**
     * Launcher corner (admin setting): bottom-right or bottom-left.
     */
    public function getPosition(): string
    {
        return $this->config->getAssistantPosition();
    }

    public function getOffsetBottom(): int
    {
        return $this->config->getAssistantOffsetBottom();
    }

    public function getOffsetSide(): int
    {
        return $this->config->getAssistantOffsetSide();
    }

    private function getBootstrap(): ?array
    {
        if (!$this->bootstrapLoaded) {
            $this->bootstrap = $this->assistantBootstrap->getBootstrap();
            $this->bootstrapLoaded = true;
        }

        return $this->bootstrap;
    }
}
