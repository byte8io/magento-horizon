<?php

declare(strict_types=1);

namespace Byte8\Horizon\Api\Diagnostics;

/**
 * Read-only diagnostics of a third-party integration installed next to
 * Horizon (first: the Byte8 PlentyONE connector), served to support agents
 * through the gateway's `integration_*` MCP tools and the store's
 * **diagnostics key**. Sources are registered on
 * {@see \Byte8\Horizon\Model\Diagnostics\SourcePool} via di.xml; each owns a
 * short `type` ("plenty") that selects it on `/horizon/api/v1/diagnostics/*`.
 *
 * Everything a source returns is handed to an AI agent, possibly outside the
 * merchant's organisation: the source must redact personal data and
 * credentials itself before returning. Horizon does not post-process it.
 */
interface SourceInterface
{
    /**
     * Short machine name of the integration ("plenty").
     */
    public function getType(): string;

    /**
     * Human label ("PlentyONE connector").
     */
    public function getLabel(): string;

    /**
     * Whether the integration is installed and enabled. An unavailable source
     * is skipped silently.
     */
    public function isAvailable(): bool;

    /**
     * The integration's diagnostic bundle: a redacted JSON-serialisable array
     * describing its state (versions, schedules, recent runs, queues, errors).
     * Sources are expected to serve a stored, periodically rebuilt bundle and
     * to rebuild on demand only when `refresh` is set.
     *
     * @param array{sku?: ?string, order?: ?string, profile?: ?string, since?: ?string, sections?: string[], refresh?: bool} $filters
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for an unusable filter value
     */
    public function snapshot(array $filters): array;

    /**
     * Entity kinds {@see explain()} accepts, e.g. ["sku", "order", "profile"].
     *
     * @return string[]
     */
    public function getExplainKinds(): array;

    /**
     * Everything the integration knows about one entity, plus the knowledge
     * sections that match its problems.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for an unknown kind or empty id
     */
    public function explain(string $kind, string $id): array;

    /**
     * Ranked sections of the integration's support knowledge.
     *
     * @return array{query: string, results: array<int, array{id: string, title: string, body: string, score: int}>}
     */
    public function searchKnowledge(string $query, int $limit): array;
}
