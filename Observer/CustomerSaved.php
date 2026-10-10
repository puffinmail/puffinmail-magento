<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Logger;
use PuffinMail\LifeCycle\Model\Mapper;
use PuffinMail\LifeCycle\Model\Outbox;

/**
 * Queues a contact update for a saved customer. The payload (address, order stats) is built by cron.
 * Syncing a contact doesn't subscribe it to the newsletter.
 */
class CustomerSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly Mapper $mapper,
        private readonly Logger $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer_data_object');
        if (!$customer instanceof CustomerInterface || !$customer->getId()) {
            return;
        }
        try {
            $storeId = $this->mapper->customerStoreId((int) $customer->getStoreId(), (int) $customer->getWebsiteId());
            if ($this->config->syncCustomers($storeId)) {
                $this->outbox->ref($storeId, Outbox::KIND_CUSTOMER, (int) $customer->getId());
            }
        } catch (DeadlockException | LockWaitException $e) {
            throw $e; // MySQL rolled back the transaction; Magento must see that.
        } catch (\Throwable $e) {
            $this->logger->error('Queuing customer #' . $customer->getId() . ' failed: ' . $e->getMessage());
        }
    }
}
