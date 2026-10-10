<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Connection;

/**
 * Saving Stores > Configuration > PuffinMail (re)connects every store view that has an API key, and reports
 * the result in the admin.
 */
class ConfigSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly StoreManagerInterface $storeManager,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->hasApiKey($storeId)) {
                continue;
            }
            try {
                $data = $this->connection->connect($storeId);
                $this->messageManager->addSuccessMessage(__(
                    '%1 is connected to PuffinMail store "%2" (%3).',
                    $store->getName(),
                    $data['store_name'],
                    $data['organization']
                ));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage(__(
                    '%1 could not connect to PuffinMail: %2',
                    $store->getName(),
                    $e->getMessage()
                ));
            }
        }
    }
}
