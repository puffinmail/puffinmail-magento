<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento puffinmail:connect — (re)connects every store view that has an API key (same as saving the
 * configuration). Use after setting the key with `config:set`.
 */
class Connect extends Command
{
    /**
     * Connect constructor.
     *
     * @param Config $config
     * @param Connection $connection
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct();
    }

    /**
     * Defines the command name and description.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('puffinmail:connect')
            ->setDescription('Connect (or refresh) every store view that has a PuffinMail API key');
    }

    /**
     * Connects each store view that has an API key and prints the result; fails if any connection fails.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = Command::SUCCESS;
        foreach ($this->storeManager->getStores() as $store) {
            if (!$this->config->hasApiKey((int) $store->getId())) {
                $output->writeln(sprintf('[%s] no API key', $store->getCode()));
                continue;
            }
            try {
                $data = $this->connection->connect((int) $store->getId());
                $output->writeln(sprintf(
                    '<info>[%s] connected to "%s" (%s), newsletter list "%s"</info>',
                    $store->getCode(),
                    $data['store_name'],
                    $data['organization'],
                    $data['list_name']
                ));
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>[%s] %s</error>', $store->getCode(), $e->getMessage()));
                $status = Command::FAILURE;
            }
        }
        return $status;
    }
}
