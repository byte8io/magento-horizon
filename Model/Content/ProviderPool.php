<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Content;

use Byte8\Horizon\Api\Content\ProviderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Fans a content search out to every available provider and merges the hits:
 * best score first, newest first within a score. Powers
 * `/horizon/api/v1/content/search` and `/horizon/api/v1/content/{type}/{id}`.
 */
class ProviderPool
{
    private const MAX_LIMIT = 20;
    private const MAX_CONTENT_CHARS = 20000;

    /** @var ProviderInterface[] keyed by type */
    private array $providers = [];

    /**
     * @param ProviderInterface[] $providers
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly HtmlText $text,
        array $providers = []
    ) {
        foreach ($providers as $provider) {
            if ($provider instanceof ProviderInterface) {
                $this->providers[$provider->getType()] = $provider;
            }
        }
    }

    /**
     * @return array{query:string,terms:string[],sources:array<int,array{type:string,label:string}>,items:array<int,array<string,mixed>>}
     */
    public function search(string $query, int $limit, ?string $type = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $storeId = (int) $this->storeManager->getStore()->getId();
        $terms = $this->text->terms($query);

        $sources = [];
        $items = [];
        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable() || ($type !== null && $provider->getType() !== $type)) {
                continue;
            }
            $sources[] = ['type' => $provider->getType(), 'label' => $provider->getLabel()];
            foreach ($provider->search($terms, $limit, $storeId) as $item) {
                if ($terms === [] || $item['score'] > 0) {
                    $items[] = $item;
                }
            }
        }

        usort($items, static function (array $a, array $b): int {
            return [$b['score'], (string) $b['published_at']] <=> [$a['score'], (string) $a['published_at']];
        });
        $items = array_slice($items, 0, $limit);
        foreach ($items as &$item) {
            unset($item['score']);
        }
        unset($item);

        return [
            'query' => $query,
            'terms' => $terms,
            'sources' => $sources,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $type, string $identifier): ?array
    {
        $provider = $this->providers[$type] ?? null;
        if ($provider === null || !$provider->isAvailable()) {
            return null;
        }
        $item = $provider->get($identifier, (int) $this->storeManager->getStore()->getId());
        if ($item === null) {
            return null;
        }
        if (mb_strlen($item['content']) > self::MAX_CONTENT_CHARS) {
            $item['content'] = mb_substr($item['content'], 0, self::MAX_CONTENT_CHARS) . '…';
            $item['truncated'] = true;
        }
        return $item;
    }

    /**
     * @return string[]
     */
    public function availableTypes(): array
    {
        $types = [];
        foreach ($this->providers as $type => $provider) {
            if ($provider->isAvailable()) {
                $types[] = $type;
            }
        }
        return $types;
    }
}
