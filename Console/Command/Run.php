<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PuffinMail\LifeCycle\Model\AbandonedCart;
use PuffinMail\LifeCycle\Model\Backfill;
use PuffinMail\LifeCycle\Model\Birthday;
use PuffinMail\LifeCycle\Model\OutboxProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento puffinmail:run outbox|abandoned-cart|birthday|backfill [--dry-run]
 */
class Run extends Command
{
    public function __construct(
        private readonly State $appState,
        private readonly OutboxProcessor $outboxProcessor,
        private readonly AbandonedCart $abandonedCart,
        private readonly Birthday $birthday,
        private readonly Backfill $backfill
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('puffinmail:run')
            ->setDescription('Run a PuffinMail job now (same as its cron job)')
            ->addArgument('job', InputArgument::REQUIRED, 'outbox | abandoned-cart | birthday | backfill')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'abandoned-cart/birthday: list who would get an event without queuing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException $e) {
            // already set
        }

        $dryRun = (bool) $input->getOption('dry-run');
        switch ($input->getArgument('job')) {
            case 'outbox':
                $lines = $this->outboxProcessor->run();
                break;
            case 'abandoned-cart':
                $lines = $this->abandonedCart->run($dryRun);
                break;
            case 'birthday':
                $lines = $this->birthday->run($dryRun);
                break;
            case 'backfill':
                $this->backfill->execute();
                $state = $this->backfill->state();
                $lines = [sprintf('backfill %s: %d customers, %d orders queued', $state['status'], $state['customers'] ?? 0, $state['orders'] ?? 0)];
                break;
            default:
                $output->writeln('<error>Unknown job. Use outbox, abandoned-cart, birthday or backfill.</error>');
                return Command::FAILURE;
        }

        foreach ($lines as $line) {
            $output->writeln(($dryRun ? '[dry-run] ' : '') . $line);
        }
        $output->writeln(sprintf('<info>%d line(s)</info>', count($lines)));
        return Command::SUCCESS;
    }
}
