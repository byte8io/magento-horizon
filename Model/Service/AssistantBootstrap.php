<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service;

use Byte8\Horizon\Model\Config;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Fetches the AI Shopping Assistant widget bootstrap from the Byte8 Horizon gateway.
 *
 * The gateway is the source of truth for the widget configuration (publishable
 * key, script URL, name, accent color); the module only holds an on/off switch.
 *
 * Both positive and negative ("enabled": false) outcomes are cached for an hour
 * so storefront page renders never hammer the gateway.
 *
 * Fail-soft: any non-200 response or network error is treated as "disabled" and
 * logged at warning level, so a misconfigured or unreachable gateway can never
 * break storefront rendering.
 */
class AssistantBootstrap
{
    private const CACHE_KEY = 'byte8_horizon_assistant_bootstrap';
    private const CACHE_LIFETIME_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns the widget bootstrap config, or null when the assistant is unavailable.
     *
     * @return array|null Decoded bootstrap payload with "enabled" === true, or null.
     */
    public function getBootstrap(): ?array
    {
        if (!$this->config->isEnabled() || !$this->config->isAssistantEnabled()) {
            return null;
        }

        $gatewayUrl = $this->config->getGatewayUrl();
        $apiKey = $this->config->getApiKey();

        if (empty($gatewayUrl) || empty($apiKey)) {
            return null;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached !== false) {
            return $this->normalize($this->decode($cached));
        }

        $bootstrap = $this->fetch($gatewayUrl, $apiKey);
        if ($bootstrap === null) {
            $bootstrap = ['enabled' => false];
        }

        $this->cache->save($this->json->serialize($bootstrap), self::CACHE_KEY, [], self::CACHE_LIFETIME_SECONDS);

        return $this->normalize($bootstrap);
    }

    /**
     * Calls the gateway bootstrap endpoint. Returns the decoded payload or null on failure.
     */
    private function fetch(string $gatewayUrl, string $apiKey): ?array
    {
        $url = $gatewayUrl . '/api/v1/assistant/bootstrap';

        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
            $this->curl->addHeader('X-Horizon-Key', $apiKey);
            $this->curl->get($url);

            $status = $this->curl->getStatus();
            if ($status !== 200) {
                $this->logger->warning(
                    sprintf('Horizon assistant bootstrap returned HTTP %d', $status),
                    ['url' => $url, 'body' => $this->curl->getBody()]
                );
                return null;
            }

            return $this->decode($this->curl->getBody());
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Horizon assistant bootstrap request failed: %s', $e->getMessage()),
                ['url' => $url]
            );
            return null;
        }
    }

    /**
     * Returns the payload only when the assistant is usable, otherwise null.
     */
    private function normalize(?array $bootstrap): ?array
    {
        if ($bootstrap === null || ($bootstrap['enabled'] ?? false) !== true) {
            return null;
        }

        $publishableKey = $bootstrap['publishable_key'] ?? null;
        $widgetUrl = $bootstrap['widget_url'] ?? null;

        if (!is_string($publishableKey) || $publishableKey === ''
            || !is_string($widgetUrl) || $widgetUrl === ''
        ) {
            return null;
        }

        return $bootstrap;
    }

    private function decode(string $raw): ?array
    {
        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Horizon assistant bootstrap payload is not valid JSON: %s', $e->getMessage())
            );
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
