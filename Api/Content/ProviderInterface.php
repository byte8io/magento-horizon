<?php

declare(strict_types=1);

namespace Byte8\Horizon\Api\Content;

/**
 * A source of editorial content an AI agent may search and read: CMS pages,
 * blog posts (Magefan today), later Amasty / Mirasvit blogs or FAQ modules.
 *
 * Providers are registered on {@see \Byte8\Horizon\Model\Content\ProviderPool}
 * via di.xml. Each one owns a short `type` ("page", "post") that becomes part
 * of the content URL on the API (`/horizon/api/v1/content/{type}/{id}`).
 */
interface ProviderInterface
{
    /**
     * Short machine name of the content type this provider serves.
     */
    public function getType(): string;

    /**
     * Human label for the type, used in listings ("Blog post").
     */
    public function getLabel(): string;

    /**
     * Whether the underlying module / tables exist and are enabled. An
     * unavailable provider is skipped silently.
     */
    public function isAvailable(): bool;

    /**
     * Keyword search. `$terms` are lower-cased words with the stop words
     * already removed; an empty list means "most recent".
     *
     * @param string[] $terms
     * @return array<int, array{type:string,id:string,title:string,url:string,excerpt:string,published_at:?string,score:int}>
     */
    public function search(array $terms, int $limit, int $storeId): array;

    /**
     * One item as plain text, or null when it does not exist for the store.
     *
     * @return array{type:string,id:string,title:string,url:string,content:string,published_at:?string}|null
     */
    public function get(string $identifier, int $storeId): ?array;
}
