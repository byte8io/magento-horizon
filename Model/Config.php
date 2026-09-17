<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_ENABLED = 'byte8_horizon/general/enabled';
    private const XML_PATH_API_KEY = 'byte8_horizon/general/api_key';
    private const XML_PATH_GATEWAY_URL = 'byte8_horizon/general/gateway_url';
    private const XML_PATH_ASSISTANT_ENABLED = 'byte8_horizon/assistant/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isAssistantEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ASSISTANT_ENABLED);
    }

    public function getApiKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY);
        if ($value) {
            return $this->encryptor->decrypt($value);
        }
        return null;
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
