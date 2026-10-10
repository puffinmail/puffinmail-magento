<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Observer;

use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Logger;
use PuffinMail\LifeCycle\Model\Outbox;

/**
 * Queues a new, refunded credit memo; cron sends it as `order.refunded` with a negative value.
 */
class CreditmemoSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly Logger $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if (!$creditmemo instanceof Creditmemo
            || !$creditmemo->getId()
            || $creditmemo->getOrigData('entity_id') !== null // Loaded and re-saved: already sent when created.
            || (int) $creditmemo->getState() !== Creditmemo::STATE_REFUNDED
        ) {
            return;
        }
        try {
            $storeId = (int) $creditmemo->getStoreId();
            if ($this->config->syncOrders($storeId)) {
                $this->outbox->ref($storeId, Outbox::KIND_CREDITMEMO, (int) $creditmemo->getId());
            }
        } catch (DeadlockException | LockWaitException $e) {
            throw $e; // MySQL rolled back the transaction; Magento must see that.
        } catch (\Throwable $e) {
            $this->logger->error('Queuing credit memo #' . $creditmemo->getIncrementId() . ' failed: ' . $e->getMessage());
        }
    }
}
