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
 * POST /horizon/api/v1/admin/order/shipment — ship an order.
 *
 * Body: `order`, optional `items` [{sku, qty}] (default: everything left),
 * `tracking` (numbers or {number, carrier, title}), `carrier` /
 * `carrier_title` defaults, `comment`, `notify`. Refused on orders with an
 * amount still due unless the merchant allows shipping unpaid orders.
 */
class OrderShipment extends AbstractWriteAction
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
        return $result->setData($this->writer->ship($body, $dryRun));
    }
}
