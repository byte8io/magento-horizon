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
 * POST /horizon/api/v1/admin/customer/newsletter — subscribe or unsubscribe.
 *
 * Body: `customer_id` or `email` (+ `website_id`), `subscribed` (bool) and
 * `consent_source` (required: where the consent, or its withdrawal, came
 * from; refused without it).
 */
class CustomerNewsletter extends AbstractWriteAction
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
        return $result->setData($this->writer->newsletter($body, $dryRun));
    }
}
