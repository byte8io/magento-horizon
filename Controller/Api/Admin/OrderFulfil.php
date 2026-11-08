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
 * POST /horizon/api/v1/admin/order/fulfil — invoice and ship in one go.
 *
 * Body: the union of order/invoice and order/shipment. With an offline (or
 * no) capture both documents are written in one transaction; with an online
 * capture a shipment failure after the capture is reported as
 * `failed_step: shipment` and the invoice stands.
 */
class OrderFulfil extends AbstractWriteAction
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
        return $result->setData($this->writer->fulfil($body, $dryRun));
    }
}
