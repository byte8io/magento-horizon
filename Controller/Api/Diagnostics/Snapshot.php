<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Diagnostics;

use Magento\Framework\Controller\Result\Json;

/**
 * `GET /horizon/api/v1/diagnostics/snapshot?source=&sku=&order=&profile=&since=&sections[]=&refresh=`:
 * the integration's redacted diagnostic bundle, narrowed by the focus filters.
 */
class Snapshot extends AbstractDiagnosticsAction
{
    protected function handleRequest(Json $result): Json
    {
        $source = $this->resolveSource();

        $payload = $source->snapshot([
            'sku' => $this->getStringParam('sku'),
            'order' => $this->getStringParam('order'),
            'profile' => $this->getStringParam('profile'),
            'since' => $this->getStringParam('since'),
            'sections' => $this->getListParam('sections'),
            'refresh' => $this->getBoolParam('refresh', false),
        ]);
        $payload['source'] = $source->getType();

        return $result->setData($payload);
    }
}
