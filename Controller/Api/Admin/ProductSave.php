<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

use Byte8\Horizon\Model\Config;
use Byte8\Horizon\Model\Config\Source\WriteScope;
use Byte8\Horizon\Model\Service\Write\ProductWriter;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * POST /horizon/api/v1/admin/product — create or update one product by SKU.
 *
 * Body: `sku` (required), then any of `name`, `description`,
 * `short_description`, `price`, `special_price`, `special_from_date`,
 * `special_to_date`, `weight`, `url_key`, `meta_title`, `meta_keyword`,
 * `meta_description`, `status` (enabled|disabled), `visibility`
 * (not_visible|catalog|search|catalog_search), `website_ids`, `category_ids`,
 * `stock` {qty, is_in_stock, source_code}, `attributes` {code: value} limited
 * to the merchant's allowlist. An unknown SKU creates a simple (or `type`:
 * virtual) product in `attribute_set` (default "Default"), DISABLED, so a
 * person enables it. Price moves above the configured cap are refused.
 */
class ProductSave extends AbstractWriteAction
{
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Config $config,
        JsonSerializer $serializer,
        LoggerInterface $logger,
        private readonly ProductWriter $writer
    ) {
        parent::__construct($request, $jsonFactory, $config, $serializer, $logger);
    }

    protected function requiredScope(): string
    {
        return WriteScope::CATALOG_WRITE;
    }

    protected function handleWrite(array $body, bool $dryRun, Json $result): Json
    {
        return $result->setData($this->writer->write($body, $dryRun, true));
    }
}
