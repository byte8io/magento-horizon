<?php

declare(strict_types=1);

namespace Byte8\Horizon\ViewModel;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Service\AssistantBootstrap;
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
        private readonly AssistantBootstrap $assistantBootstrap
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

    public function getAccentColor(): string
    {
        $value = $this->getBootstrap()['accent_color'] ?? null;

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_ACCENT_COLOR;
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
