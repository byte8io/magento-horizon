<?php

declare(strict_types=1);

namespace Byte8\Horizon\Model\Service\Write;

use Byte8\Horizon\Controller\Api\Admin\WriteException;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;

/**
 * Stock changes for a product write. With MSI enabled the quantity lives on
 * a source item (default source unless `source_code` is given); otherwise on
 * the legacy stock item. The MSI contracts are resolved lazily so the module
 * still loads on installs without the Inventory modules.
 */
class StockWriter
{
    private const DEFAULT_SOURCE = 'default';

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * Validate the stock payload and compute the change set without writing.
     *
     * @return array{source_code: string, qty: ?float, is_in_stock: ?bool, changes: array<int, array<string, mixed>>}
     */
    public function plan(string $sku, mixed $stock, bool $isNew): array
    {
        if (!is_array($stock)) {
            throw new WriteException('"stock" must be an object with "qty" and/or "is_in_stock"', 'VALIDATION', 400);
        }
        $qty = null;
        if (array_key_exists('qty', $stock) && $stock['qty'] !== null) {
            if (!is_numeric($stock['qty']) || (float) $stock['qty'] < 0) {
                throw new WriteException('"stock.qty" must be a non-negative number', 'VALIDATION', 400);
            }
            $qty = (float) $stock['qty'];
        }
        $inStock = null;
        if (array_key_exists('is_in_stock', $stock) && $stock['is_in_stock'] !== null) {
            $inStock = filter_var($stock['is_in_stock'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new WriteException('"stock.is_in_stock" must be true or false', 'VALIDATION', 400);
        }
        if ($qty === null && $inStock === null) {
            throw new WriteException('"stock" needs "qty" and/or "is_in_stock"', 'VALIDATION', 400);
        }
        if ($qty !== null && $inStock === null) {
            // The usual intent: restock means available, zero means sold out.
            $inStock = $qty > 0;
        }
        $sourceCode = $this->resolveSource($sku, $stock['source_code'] ?? null, $isNew);
        $current = $isNew ? ['qty' => null, 'is_in_stock' => null] : $this->current($sku, $sourceCode);

        $changes = [];
        if ($qty !== null && ($isNew || $current['qty'] === null || abs($current['qty'] - $qty) > 0.000001)) {
            $changes[] = ['field' => 'stock.qty', 'before' => $current['qty'], 'after' => $qty];
        }
        if ($inStock !== null && ($isNew || $current['is_in_stock'] !== $inStock)) {
            $changes[] = ['field' => 'stock.is_in_stock', 'before' => $current['is_in_stock'], 'after' => $inStock];
        }
        if ($changes !== [] && $this->msi() && $sourceCode !== self::DEFAULT_SOURCE && !$this->sourceExists($sourceCode)) {
            throw new WriteException(sprintf('No inventory source "%s"', $sourceCode), 'VALIDATION', 400);
        }
        return ['source_code' => $sourceCode, 'qty' => $qty, 'is_in_stock' => $inStock, 'changes' => $changes];
    }

    /**
     * @param array{source_code: string, qty: ?float, is_in_stock: ?bool, changes: array<int, array<string, mixed>>} $plan
     */
    public function apply(string $sku, array $plan): void
    {
        if ($plan['changes'] === []) {
            return;
        }
        if ($this->msi()) {
            $this->applyMsi($sku, $plan);
            return;
        }
        $item = $this->stockRegistry->getStockItemBySku($sku);
        if ($plan['qty'] !== null) {
            $item->setQty($plan['qty']);
        }
        if ($plan['is_in_stock'] !== null) {
            $item->setIsInStock($plan['is_in_stock']);
        }
        $this->stockRegistry->updateStockItemBySku($sku, $item);
    }

    /**
     * @param array{source_code: string, qty: ?float, is_in_stock: ?bool} $plan
     */
    private function applyMsi(string $sku, array $plan): void
    {
        $current = $this->current($sku, $plan['source_code']);
        /** @var \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory $factory */
        $factory = $this->objectManager->get(\Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class);
        /** @var \Magento\InventoryApi\Api\SourceItemsSaveInterface $save */
        $save = $this->objectManager->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class);
        $item = $factory->create();
        $item->setSku($sku);
        $item->setSourceCode($plan['source_code']);
        $item->setQuantity($plan['qty'] ?? ($current['qty'] ?? 0.0));
        $inStock = $plan['is_in_stock'] ?? ($current['is_in_stock'] ?? false);
        $item->setStatus($inStock ? 1 : 0);
        $save->execute([$item]);
    }

    /**
     * @return array{qty: ?float, is_in_stock: ?bool}
     */
    private function current(string $sku, string $sourceCode): array
    {
        $connection = $this->resourceConnection->getConnection();
        if ($this->msi()) {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resourceConnection->getTableName('inventory_source_item'), ['quantity', 'status'])
                    ->where('sku = ?', $sku)
                    ->where('source_code = ?', $sourceCode)
            );
            if (!$row) {
                return ['qty' => null, 'is_in_stock' => null];
            }
            return ['qty' => (float) $row['quantity'], 'is_in_stock' => (int) $row['status'] === 1];
        }
        try {
            $item = $this->stockRegistry->getStockItemBySku($sku);
        } catch (\Exception) {
            return ['qty' => null, 'is_in_stock' => null];
        }
        return ['qty' => (float) $item->getQty(), 'is_in_stock' => (bool) $item->getIsInStock()];
    }

    /**
     * Which MSI source a write without `source_code` means: the one source the
     * SKU already has stock on; `default` when it has none (new products);
     * an explicit choice when it is stocked on several.
     */
    private function resolveSource(string $sku, mixed $requested, bool $isNew): string
    {
        $requested = trim((string) ($requested ?? ''));
        if ($requested !== '' || !$this->msi() || $isNew) {
            return $requested !== '' ? $requested : self::DEFAULT_SOURCE;
        }
        $connection = $this->resourceConnection->getConnection();
        $codes = $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('inventory_source_item'), ['source_code'])
                ->where('sku = ?', $sku)
        );
        if (count($codes) === 1) {
            return (string) $codes[0];
        }
        if (count($codes) > 1) {
            throw new WriteException(
                sprintf('"%s" is stocked on several sources (%s); send "stock.source_code"', $sku, implode(', ', $codes)),
                'VALIDATION',
                400
            );
        }
        return self::DEFAULT_SOURCE;
    }

    private function sourceExists(string $sourceCode): bool
    {
        $connection = $this->resourceConnection->getConnection();
        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('inventory_source'), ['source_code'])
                ->where('source_code = ?', $sourceCode)
        );
    }

    private function msi(): bool
    {
        if (!$this->moduleManager->isEnabled('Magento_InventoryApi')) {
            return false;
        }
        $connection = $this->resourceConnection->getConnection();
        return $connection->isTableExists($this->resourceConnection->getTableName('inventory_source_item'));
    }
}
