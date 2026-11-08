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
 * POST /horizon/api/v1/admin/customer — create or update ONE customer.
 *
 * Body: `action` (create | update). Create: `email`, `firstname`, `lastname`,
 * optional `website_id` / `store_id` (default: this store), `group` (name) or
 * `group_id`, `dob`, `gender`, `taxvat`, `middlename`, `prefix`, `suffix`,
 * `attributes` (allowlisted custom attributes), `addresses[]`,
 * `send_welcome_email` (default true: Magento emails a set-password link; the
 * agent never sets a password). Update: `id` or `email` (+ `website_id`) and
 * the same profile fields. Email changes and deletion are not offered.
 */
class CustomerSave extends AbstractWriteAction
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
        $action = strtolower((string) ($body['action'] ?? 'update'));
        unset($body['action']);
        if (!in_array($action, ['create', 'update'], true)) {
            throw new WriteException('"action" must be create or update', 'VALIDATION', 400);
        }
        return $result->setData(
            $action === 'create' ? $this->writer->create($body, $dryRun) : $this->writer->update($body, $dryRun)
        );
    }
}
