<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Webhook;

use Byte8\Horizon\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * Pushes catalog invalidation webhooks to the Byte8 Horizon gateway.
 *
 * Payload shape mirrors the gateway's `WebhookPayload` struct:
 *   { "event": "<snake_case event>", "entity_ids": [u64], "timestamp": "ISO-8601" }
 *
 * Auth is the same `X-Horizon-Key` header the gateway uses for inbound calls.
 *
 * Fail-soft: failures are logged but never thrown, so a misconfigured gateway
 * URL cannot block a product/category/stock save on the Magento side.
 */
class Notifier
{
    public const EVENT_PRODUCT_CREATED   = 'product_created';
    public const EVENT_PRODUCT_UPDATED   = 'product_updated';
    public const EVENT_PRODUCT_DELETED   = 'product_deleted';
    public const EVENT_CATEGORY_CREATED  = 'category_created';
    public const EVENT_CATEGORY_UPDATED  = 'category_updated';
    public const EVENT_CATEGORY_DELETED  = 'category_deleted';
    public const EVENT_INVENTORY_UPDATED = 'inventory_updated';
    public const EVENT_FULL_REINDEX      = 'full_reindex';

    private const REQUEST_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $event     One of the EVENT_* constants.
     * @param int[]  $entityIds IDs affected by the event.
     */
    public function notify(string $event, array $entityIds): void
    {
        $targets = $this->targets();
        if ($targets === []) {
            return;
        }

        $payload = [
            'event' => $event,
            'entity_ids' => array_values(array_map('intval', $entityIds)),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        foreach ($targets as [$gatewayUrl, $apiKey]) {
            $this->send($gatewayUrl . '/webhooks/invalidate', $apiKey, $event, $payload);
        }
    }

    /**
     * One [gateway URL, API key] pair per connected Horizon store. Store views
     * that share a key (the default-scope setup) are notified once.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function targets(): array
    {
        $targets = [];
        // null = the current scope, for a key set at default scope only
        foreach (array_merge([null], $this->config->getEnabledStoreIds()) as $storeId) {
            if (!$this->config->isEnabled($storeId)) {
                continue;
            }
            $gatewayUrl = $this->config->getGatewayUrl($storeId);
            $apiKey = $this->config->getApiKey($storeId);
            if (!empty($gatewayUrl) && !empty($apiKey)) {
                $targets[$gatewayUrl . '|' . $apiKey] = [$gatewayUrl, $apiKey];
            }
        }
        return array_values($targets);
    }

    private function send(string $url, string $apiKey, string $event, array $payload): void
    {
        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
            $this->curl->setHeaders([
                'Content-Type' => 'application/json',
                'X-Horizon-Key' => $apiKey,
            ]);
            $this->curl->post($url, json_encode($payload));

            $status = $this->curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning(
                    sprintf('Horizon webhook returned HTTP %d for event %s', $status, $event),
                    ['url' => $url, 'body' => $this->curl->getBody()]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Horizon webhook delivery failed for event %s: %s', $event, $e->getMessage()),
                ['url' => $url]
            );
        }
    }
}
