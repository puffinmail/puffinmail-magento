<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Console\Command;

use PuffinMail\LifeCycle\Model\CatalogSync;
use PuffinMail\LifeCycle\Model\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento puffinmail:catalog [--restart] — catalogue sync state per connected store view; --restart sends the
 * whole catalogue again (cron picks it up within a minute).
 */
class Catalog extends Command
{
    public function __construct(
        private readonly CatalogSync $catalogSync,
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('puffinmail:catalog')
            ->setDescription('Show the PuffinMail catalogue sync, or send the whole catalogue again')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Send every product again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->connection->connectedStores() as $store) {
            $storeId = (int) $store->getId();
            if ($input->getOption('restart')) {
                $this->catalogSync->restart($storeId);
            }
            $state = $this->catalogSync->state($storeId);
            $output->writeln(sprintf(
                '[%s] %s · %d products queued%s',
                $store->getCode(),
                $state['status'] ?? 'not started',
                (int) ($state['products'] ?? 0),
                empty($state['finished_at']) ? '' : ' · finished ' . date('Y-m-d H:i', (int) $state['finished_at'])
            ));
        }
        return Command::SUCCESS;
    }
}
