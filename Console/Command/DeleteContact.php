<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Outbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento puffinmail:delete-contact jane@example.com
 * Privacy erasure: deletes the contact from every connected PuffinMail store and forgets their saved carts here.
 */
class DeleteContact extends Command
{
    /**
     * DeleteContact constructor.
     *
     * @param Config $config
     * @param Outbox $outbox
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct();
    }

    /**
     * Defines the command name, description and arguments.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('puffinmail:delete-contact')
            ->setDescription('Erase a contact from PuffinMail (privacy requests)')
            ->addArgument('email', InputArgument::REQUIRED, 'Email address');
    }

    /**
     * Queues the deletion once per PuffinMail store and removes the address's saved cart records.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $this->outbox->normalize((string) $input->getArgument('email'));
        if ($email === '') {
            $output->writeln('<error>Not a valid email address.</error>');
            return Command::FAILURE;
        }

        $keys = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $key = $this->config->getApiKey($storeId);
            if ($key === '' || isset($keys[$key])) {
                continue; // One deletion per PuffinMail store.
            }
            $keys[$key] = true;
            $this->outbox->deleteContact($storeId, $email);
            $output->writeln(sprintf('[%s] deletion queued', $store->getCode()));
        }
        $carts = $this->resource->getConnection()->delete(
            $this->resource->getTableName('puffinmail_cart'),
            ['email = ?' => $email]
        );
        $output->writeln(
            sprintf('<info>Removed %d saved cart record(s). The outbox cron sends the deletions.</info>', $carts)
        );
        return Command::SUCCESS;
    }
}
