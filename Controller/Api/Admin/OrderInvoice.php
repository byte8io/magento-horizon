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
 * POST /horizon/api/v1/admin/order/invoice — invoice an order.
 *
 * Body: `order` (increment id or entity id), optional `items` [{sku, qty}]
 * for a partial invoice (default: everything left), `capture`
 * (online | offline | none; default online when the payment method supports
 * it and the store allows it, otherwise offline), `comment`, `notify`
 * (email the customer). Refused when the order cannot be invoiced (on hold,
 * cancelled, complete, already invoiced) or when online capture is asked for
 * but unavailable.
 */
class OrderInvoice extends AbstractWriteAction
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
        return $result->setData($this->writer->invoice($body, $dryRun));
    }
}
