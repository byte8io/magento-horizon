<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Diagnostics;

use Magento\Framework\Controller\Result\Json;

/**
 * `GET /horizon/api/v1/diagnostics/explain?source=&kind=sku&id=ABC`: everything
 * the integration knows about one entity.
 */
class Explain extends AbstractDiagnosticsAction
{
    protected function handleRequest(Json $result): Json
    {
        $source = $this->resolveSource();

        $kind = strtolower((string) $this->getStringParam('kind'));
        $id = (string) $this->getStringParam('id');
        if ($kind === '' || $id === '') {
            throw new DiagnosticsException('Parameters "kind" and "id" are required', 'BAD_REQUEST', 400);
        }
        if (!in_array($kind, $source->getExplainKinds(), true)) {
            throw new DiagnosticsException(
                sprintf('Unknown kind "%s"; %s explains: %s', $kind, $source->getLabel(), implode(', ', $source->getExplainKinds())),
                'BAD_REQUEST',
                400
            );
        }

        $payload = $source->explain($kind, $id);
        $payload['source'] = $source->getType();

        return $result->setData($payload);
    }
}
