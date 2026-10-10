<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Console\Command;

use PuffinMail\LifeCycle\Model\Backfill as BackfillModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento puffinmail:backfill [--months=12] [--cancel]
 * Starts the historical sync; cron does the work (or run `puffinmail:run backfill` then `puffinmail:run outbox`).
 */
class Backfill extends Command
{
    public function __construct(
        private readonly BackfillModel $backfill
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('puffinmail:backfill')
            ->setDescription('Sync existing customers and order history to PuffinMail (no emails are sent)')
            ->addOption('months', null, InputOption::VALUE_REQUIRED, 'Orders from the last N months; 0 = all', '12')
            ->addOption('cancel', null, InputOption::VALUE_NONE, 'Stop a running historical sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('cancel')) {
            $this->backfill->cancel();
            $output->writeln('<info>Historical sync stopped.</info>');
            return Command::SUCCESS;
        }
        $months = max(0, (int) $input->getOption('months'));
        $this->backfill->start($months);
        $output->writeln(sprintf(
            '<info>Historical sync started (%s). Cron queues customers then orders; progress: bin/magento puffinmail:status</info>',
            $months > 0 ? 'orders from the last ' . $months . ' months' : 'all orders'
        ));
        return Command::SUCCESS;
    }
}
