<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Observer;

use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Logger;
use PuffinMail\LifeCycle\Model\Outbox;

/**
 * Queues an order reference on every order save; cron works out which events its state calls for
 * (repeat saves of the same order collapse into one reference).
 */
class OrderSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly Logger $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$order->getId()) {
            return;
        }
        try {
            $storeId = (int) $order->getStoreId();
            // Cart recovery needs orders even when order events are off.
            if ($this->config->syncOrders($storeId)
                || ($this->config->hasApiKey($storeId) && $this->config->isSetFlag('abandoned_cart/enabled', $storeId))
            ) {
                $this->outbox->ref($storeId, Outbox::KIND_ORDER, (int) $order->getId());
            }
        } catch (DeadlockException | LockWaitException $e) {
            throw $e; // MySQL rolled back the order's transaction; Magento must see that.
        } catch (\Throwable $e) {
            $this->logger->error('Queuing order #' . $order->getIncrementId() . ' failed: ' . $e->getMessage());
        }
    }
}
