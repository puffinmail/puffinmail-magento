<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PuffinMail\LifeCycle\Model\AbandonedCart;
use PuffinMail\LifeCycle\Model\Birthday;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento puffinmail:test-email cart <quote id> you@example.com [--reminder=2]
 * bin/magento puffinmail:test-email birthday <customer id> you@example.com
 *
 * Sends the event straight to PuffinMail for the given address (notify=always), so its transactional email
 * goes out with real cart/customer data. Nothing is recorded and no coupon is generated (TESTCODE instead).
 */
class TestEmail extends Command
{
    /**
     * TestEmail constructor.
     *
     * @param State $appState
     * @param AbandonedCart $abandonedCart
     * @param Birthday $birthday
     */
    public function __construct(
        private readonly State $appState,
        private readonly AbandonedCart $abandonedCart,
        private readonly Birthday $birthday
    ) {
        parent::__construct();
    }

    /**
     * Defines the command name, description, arguments and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('puffinmail:test-email')
            ->setDescription('Send an abandoned-cart or birthday event for real data to a test address')
            ->addArgument('type', InputArgument::REQUIRED, 'cart | birthday')
            ->addArgument('id', InputArgument::REQUIRED, 'Quote ID (cart) or customer ID (birthday)')
            ->addArgument('to', InputArgument::REQUIRED, 'Recipient email address')
            ->addOption('reminder', null, InputOption::VALUE_REQUIRED, 'cart: reminder 1 or 2', '1');
    }

    /**
     * Sends the test event and prints what PuffinMail did with it.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            // Intentionally ignored: the area code is already set, which is fine.
        }

        $id = (int) $input->getArgument('id');
        $to = (string) $input->getArgument('to');
        try {
            $result = match ($input->getArgument('type')) {
                'cart' => $this->abandonedCart->sendTest($id, $to, (int) $input->getOption('reminder')),
                'birthday' => $this->birthday->sendTest($id, $to),
                default => throw new \InvalidArgumentException('Type must be cart or birthday'),
            };
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($result['ok'])) {
            $output->writeln(
                '<error>PuffinMail rejected the event: ' . ($result['error'] ?? 'no reason given') . '</error>'
            );
            return Command::FAILURE;
        }
        $email = (string) ($result['email'] ?? '');
        $output->writeln(match ($email) {
            'sent' => '<info>Sent to ' . $to . '</info>',
            'not_configured' => '<comment>Recorded, but no email: this store email is off or not set up yet'
                . ' (Store emails in PuffinMail).</comment>',
            default => '<comment>Recorded; email status: ' . ($email ?: 'none') . '</comment>',
        });
        return Command::SUCCESS;
    }
}
