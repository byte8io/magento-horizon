<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api;

use Byte8\AiVisibility\Api\VisibilityReportInterface;
use Byte8\Horizon\Model\Config;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /horizon/api/v1/ai-visibility?days=30 — AI crawler visits and AI
 * referral sessions/orders, as recorded by the free Byte8_AiVisibility
 * module. The gateway shows this on the store's AI readiness page.
 */
class AiVisibility extends AbstractAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        private readonly VisibilityReportInterface $visibilityReport
    ) {
        parent::__construct($request, $jsonFactory, $config);
    }

    protected function handleRequest(Json $result): Json
    {
        $days = max(1, min(365, $this->getIntParam('days', 30)));

        return $result->setData($this->visibilityReport->getReport($days));
    }
}
