<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\WriteScope;
use Byte8\Horizon\Model\Service\Write\CustomerWriter;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * POST /horizon/api/v1/admin/customer/address — add or update an address.
 *
 * Body: `customer_id` or `email` (+ `website_id`), optional `address_id` to
 * update, then `firstname`, `lastname` (default: the customer's), `company`,
 * `street` (string or lines), `city`, `region` (name or code) / `region_id`,
 * `postcode`, `country_id`, `telephone`, `vat_id`, `default_billing`,
 * `default_shipping`.
 */
class CustomerAddressSave extends AbstractWriteAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        JsonSerializer $serializer,
        LoggerInterface $logger,
        private readonly CustomerWriter $writer
    ) {
        parent::__construct($request, $jsonFactory, $config, $serializer, $logger);
    }

    protected function requiredScope(): string
    {
        return WriteScope::CUSTOMERS_WRITE;
    }

    protected function handleWrite(array $body, bool $dryRun, Json $result): Json
    {
        return $result->setData($this->writer->saveAddress($body, $dryRun));
    }
}
