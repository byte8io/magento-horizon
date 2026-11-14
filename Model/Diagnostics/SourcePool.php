<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Diagnostics;

use Byte8\Horizon\Api\Diagnostics\SourceInterface;

/**
 * The diagnostics sources registered through di.xml, keyed by type. Powers
 * `/horizon/api/v1/diagnostics/*` and the `diagnostics` block of the health
 * endpoint.
 */
class SourcePool
{
    /** @var SourceInterface[] keyed by type */
    private array $sources = [];

    /**
     * @param SourceInterface[] $sources
     */
    public function __construct(array $sources = [])
    {
        foreach ($sources as $source) {
            if ($source instanceof SourceInterface) {
                $this->sources[$source->getType()] = $source;
            }
        }
    }

    /**
     * @return SourceInterface[] keyed by type, available ones only
     */
    public function getAvailable(): array
    {
        return array_filter($this->sources, static fn (SourceInterface $s) => $s->isAvailable());
    }

    /**
     * The source of the given type, or the only available source when no type
     * is given and exactly one exists. Null otherwise.
     */
    public function get(?string $type): ?SourceInterface
    {
        $available = $this->getAvailable();
        if ($type === null || $type === '') {
            return count($available) === 1 ? reset($available) : null;
        }

        return $available[$type] ?? null;
    }

    /**
     * @return array<int, array{type: string, label: string, explain_kinds: string[]}>
     */
    public function describe(): array
    {
        $result = [];
        foreach ($this->getAvailable() as $source) {
            $result[] = [
                'type' => $source->getType(),
                'label' => $source->getLabel(),
                'explain_kinds' => array_values($source->getExplainKinds()),
            ];
        }

        return $result;
    }
}
