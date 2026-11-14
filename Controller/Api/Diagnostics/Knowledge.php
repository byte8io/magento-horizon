<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Diagnostics;

use Magento\Framework\Controller\Result\Json;

/**
 * `GET /horizon/api/v1/diagnostics/knowledge?source=&q=&limit=`: ranked
 * sections of the integration's support knowledge for a query.
 */
class Knowledge extends AbstractDiagnosticsAction
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 20;

    protected function handleRequest(Json $result): Json
    {
        $source = $this->resolveSource();

        $query = trim((string) $this->getStringParam('q'));
        if ($query === '') {
            throw new DiagnosticsException('Parameter "q" is required', 'BAD_REQUEST', 400);
        }
        $limit = max(1, min(self::MAX_LIMIT, $this->getIntParam('limit', self::DEFAULT_LIMIT)));

        $payload = $source->searchKnowledge($query, $limit);
        $payload['source'] = $source->getType();

        return $result->setData($payload);
    }
}
