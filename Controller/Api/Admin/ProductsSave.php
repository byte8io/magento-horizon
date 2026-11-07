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
 * POST /horizon/api/v1/admin/products — update several existing products in
 * one call. Body: `items: [{sku, ...same fields as /admin/product}]`, at most
 * the configured maximum. Unknown SKUs are refused (bulk never creates).
 *
 * Every item is validated as a dry run first; one invalid item refuses the
 * whole call, so a confirmed write never applies half a price list because
 * item 37 had a typo. Applying still happens item by item (Magento has no
 * cross-product transaction), so the response reports `saved` per item and
 * stops at the first failure.
 */
class ProductsSave extends AbstractWriteAction
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
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === [] || array_keys($items) !== range(0, count($items) - 1)) {
            throw new WriteException('"items" must be a non-empty array of product payloads', 'VALIDATION', 400);
        }
        $max = $this->config->getMaxBulkProducts();
        if (count($items) > $max) {
            throw new WriteException(
                sprintf('%d items sent; this store allows at most %d products per bulk write', count($items), $max),
                'BULK_CAP',
                422
            );
        }
        $seen = [];
        $errors = [];
        $previews = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                $errors[] = ['index' => $i, 'sku' => null, 'error' => 'Item must be an object', 'code' => 'VALIDATION'];
                continue;
            }
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku !== '' && isset($seen[$sku])) {
                $errors[] = ['index' => $i, 'sku' => $sku, 'error' => 'SKU appears more than once', 'code' => 'VALIDATION'];
                continue;
            }
            $seen[$sku] = true;
            try {
                $previews[$i] = $this->writer->write($item, true, false);
            } catch (WriteException $e) {
                $errors[] = ['index' => $i, 'sku' => $sku !== '' ? $sku : null, 'error' => $e->getMessage(), 'code' => $e->getCode_()];
            }
        }
        if ($errors !== []) {
            return $result->setHttpResponseCode(400)->setData([
                'error' => sprintf('%d of %d items are invalid; nothing was changed', count($errors), count($items)),
                'code' => 'VALIDATION',
                'items' => $errors,
            ]);
        }

        $out = [];
        $saved = 0;
        $noop = 0;
        $failed = null;
        foreach ($items as $i => $item) {
            $preview = $previews[$i];
            if ($dryRun || $preview['noop'] || $failed !== null) {
                $preview['saved'] = false;
                $preview['skipped'] = $failed !== null && !$preview['noop'];
                $out[] = $preview;
                $noop += $preview['noop'] ? 1 : 0;
                continue;
            }
            try {
                $applied = $this->writer->write($item, false, false);
                $saved += $applied['saved'] ? 1 : 0;
                $out[] = $applied;
            } catch (\Throwable $e) {
                $this->logger->error('Horizon bulk product write failed: ' . $e->getMessage(), ['exception' => $e, 'sku' => $preview['sku']]);
                $preview['saved'] = false;
                $preview['error'] = $e instanceof WriteException ? $e->getMessage() : 'Save failed';
                $out[] = $preview;
                $failed = $i;
            }
        }

        return $result->setData([
            'action' => 'bulk_update',
            'dry_run' => $dryRun,
            'saved' => $saved > 0,
            'noop' => $noop === count($items),
            'entity_type' => 'product',
            'entity_id' => null,
            'total' => count($items),
            'saved_count' => $saved,
            'noop_count' => $noop,
            'failed_index' => $failed,
            'items' => $out,
            'changes' => $this->flatten($out),
        ]);
    }

    /**
     * One combined change list (field prefixed with the SKU) for the audit diff.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $items): array
    {
        $changes = [];
        foreach ($items as $item) {
            foreach ($item['changes'] ?? [] as $change) {
                $changes[] = [
                    'field' => $item['sku'] . '.' . $change['field'],
                    'before' => $change['before'],
                    'after' => $change['after'],
                ];
            }
        }
        return $changes;
    }
}
