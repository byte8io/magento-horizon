<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\WriteScope;
use Byte8\Horizon\Model\Service\Write\OrderWriter;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * POST /horizon/api/v1/admin/order/hold — put an order on hold or release it.
 *
 * Body: `order`, `hold` (true = hold, false = unhold), optional `comment`.
 */
class OrderHold extends AbstractWriteAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        JsonSerializer $serializer,
        LoggerInterface $logger,
        private readonly OrderWriter $writer
    ) {
        parent::__construct($request, $jsonFactory, $config, $serializer, $logger);
    }

    protected function requiredScope(): string
    {
        return WriteScope::SALES_WRITE;
    }

    protected function handleWrite(array $body, bool $dryRun, Json $result): Json
    {
        return $result->setData($this->writer->hold($body, $dryRun, filter_var($body['hold'] ?? true, FILTER_VALIDATE_BOOLEAN)));
    }
}
