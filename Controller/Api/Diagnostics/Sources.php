<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Diagnostics;

use Magento\Framework\Controller\Result\Json;

/**
 * `GET /horizon/api/v1/diagnostics/sources`: the integrations on this store
 * that report diagnostics and the entity kinds each can explain.
 */
class Sources extends AbstractDiagnosticsAction
{
    protected function handleRequest(Json $result): Json
    {
        return $result->setData(['sources' => $this->sources->describe()]);
    }
}
