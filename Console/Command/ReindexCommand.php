<?php

declare(strict_types=1);

namespace Byte8\Horizon\Console\Command;

use Byte8\Horizon\Model\Indexer\Product\Action;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Back-compat alias for `bin/magento indexer:reindex byte8_horizon_product`.
 *
 * Runs the full rebuild through the indexer framework so the indexer state
 * (valid / invalid, last updated) stays correct.
 */
class ReindexCommand extends Command
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ResourceConnection $resourceConnection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('horizon:reindex');
        $this->setDescription(
            'Rebuild the Horizon AI product index (alias of "indexer:reindex byte8_horizon_product")'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Horizon: rebuilding product index...</info>');

        try {
            $this->indexerRegistry->get(Action::INDEXER_ID)->reindexAll();

            $connection = $this->resourceConnection->getConnection();
            $count = (int) $connection->fetchOne(
                $connection->select()
                    ->from($this->resourceConnection->getTableName('byte8_horizon_product_index'), 'COUNT(*)')
            );

            $output->writeln("<info>Done. Indexed {$count} products.</info>");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Reindex failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
