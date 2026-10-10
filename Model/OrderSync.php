<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * Order lifecycle events. `order.placed` carries the order total as purchase revenue once the order is paid
 * (processing/complete); cancelling a paid order withdraws that revenue again, and refunds are sent as
 * negative values. puffinmail_order remembers what was sent, because re-sending an event re-runs the
 * automations it triggers.
 */
class OrderSync
{
    private const TABLE = 'puffinmail_order';

    public function __construct(
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly Mapper $mapper,
        private readonly AbandonedCart $abandonedCart,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Queues the events the order's current state calls for, plus a contact update (stats change with orders).
     *
     * @param bool $backfill historical import: only the purchase and its refunds, flagged so no emails go out
     *                       and lifecycle automations (completed, cancelled) don't fire for old orders
     */
    public function sync(Order $order, bool $backfill = false): void
    {
        if (!$order->getCustomerEmail()) {
            return;
        }
        $storeId = (int) $order->getStoreId();
        if (!$this->config->syncOrders($storeId)) {
            // Queued only for cart recovery (abandoned carts on, order events off).
            if (!$backfill) {
                $this->abandonedCart->orderPlaced($order);
            }
            return;
        }
        $orderId = (int) $order->getId();
        $state = (string) $order->getState();
        $paid = in_array($state, Mapper::PAID_STATES, true);
        $sent = $this->sent($orderId);

        if ($backfill) {
            if ($paid && !$sent['placed_sent']) {
                $this->outbox->event($storeId, ['backfill' => true] + $this->mapper->orderEvent($order, 'order.placed'));
                foreach ($order->getCreditmemosCollection() ?: [] as $creditmemo) {
                    $this->outbox->event($storeId, ['backfill' => true] + $this->mapper->refundEvent($order, $creditmemo));
                }
                $this->save($orderId, ['placed_sent' => 1, 'completed_sent' => (int) ($state === Order::STATE_COMPLETE)]);
            }
            $this->outbox->contact($storeId, $this->mapper->contactFromOrder($order));
            return;
        }

        if ($paid && !$sent['placed_sent']) {
            $this->outbox->event($storeId, $this->mapper->orderEvent($order, 'order.placed'));
            $sent['placed_sent'] = 1;
        }
        if ($state === Order::STATE_COMPLETE && !$sent['completed_sent']) {
            $this->outbox->event($storeId, $this->mapper->orderEvent($order, 'order.completed'));
            $sent['completed_sent'] = 1;
        }
        if ($state === Order::STATE_CANCELED && $sent['placed_sent'] && !$sent['cancelled_sent']) {
            // Withdraw the purchase (deleting the event removes its revenue), then record the cancellation.
            $this->outbox->event($storeId, ['deleted' => true] + $this->mapper->orderEvent($order, 'order.placed'));
            $this->outbox->event($storeId, $this->mapper->orderEvent($order, 'order.cancelled'));
            $sent['placed_sent'] = 0;
            $sent['cancelled_sent'] = 1;
        }
        $this->save($orderId, $sent);

        $this->outbox->contact($storeId, $this->mapper->contactFromOrder($order));
        $this->abandonedCart->orderPlaced($order);
    }

    public function syncRefund(Creditmemo $creditmemo): void
    {
        $order = $creditmemo->getOrder();
        if (!$order || !$order->getCustomerEmail() || !$this->config->syncOrders((int) $order->getStoreId())) {
            return;
        }
        $storeId = (int) $order->getStoreId();
        $this->outbox->event($storeId, $this->mapper->refundEvent($order, $creditmemo));
        $this->outbox->contact($storeId, $this->mapper->contactFromOrder($order));
    }

    /** @return array{placed_sent: int, completed_sent: int, cancelled_sent: int} */
    private function sent(int $orderId): array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['placed_sent', 'completed_sent', 'cancelled_sent'])
                ->where('order_id = ?', $orderId)
        );
        return array_map('intval', $row ?: ['placed_sent' => 0, 'completed_sent' => 0, 'cancelled_sent' => 0]);
    }

    private function save(int $orderId, array $sent): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['order_id' => $orderId] + $sent,
            array_keys($sent)
        );
    }
}
