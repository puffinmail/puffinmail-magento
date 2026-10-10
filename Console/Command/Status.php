<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Backfill;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Connection;
use PuffinMail\LifeCycle\Model\Outbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento puffinmail:status — connection per store view, queue counts, recent failures, historical sync.
 */
class Status extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly Backfill $backfill,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('puffinmail:status')
            ->setDescription('Show PuffinMail connection, queue and historical-sync status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $data = $this->connection->get($storeId);
            $stats = $this->outbox->stats($storeId);
            $state = match (true) {
                !$this->config->hasApiKey($storeId) => 'no API key',
                !empty($data['auth_error']) => 'KEY REJECTED: ' . $data['auth_error'],
                empty($data['store_id']) => 'not connected' . (isset($data['last_error']) ? ': ' . $data['last_error'] : ''),
                default => sprintf('connected to "%s" (%s), list "%s"', $data['store_name'], $data['organization'], $data['list_name']),
            };
            $output->writeln(sprintf(
                '[%s] %s · pending %d · failed %d',
                $store->getCode(),
                $state,
                $stats[Outbox::STATUS_PENDING],
                $stats[Outbox::STATUS_FAILED]
            ));
        }
        foreach ($this->outbox->recentFailures(10) as $failure) {
            $output->writeln(sprintf('  failed %s %s: %s', $failure['updated_at'], $failure['dedupe_key'], $failure['last_error']));
        }
        $backfill = $this->backfill->state();
        $output->writeln(sprintf(
            'Historical sync: %s%s',
            $backfill['status'],
            isset($backfill['customers']) ? sprintf(' (%d customers, %d orders queued)', $backfill['customers'], $backfill['orders']) : ''
        ));
        return Command::SUCCESS;
    }
}
