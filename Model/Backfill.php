<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;

/**
 * One-off historical sync, started from the admin status panel or `bin/magento puffinmail:backfill`: every
 * customer, then paid orders from the chosen period (purchases and refunds only, flagged `backfill` so no
 * emails or automations fire). Doesn't subscribe anyone.
 *
 * Cron (every minute) pages through by entity id and queues references; OutboxProcessor builds and sends them.
 */
class Backfill
{
    private const FLAG = 'puffinmail_backfill';
    private const PAGE = 500;
    private const TIME_BUDGET = 30;

    /**
     * Backfill constructor.
     *
     * @param Config $config
     * @param Outbox $outbox
     * @param Mapper $mapper
     * @param ResourceConnection $resource
     * @param FlagManager $flagManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly Mapper $mapper,
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager
    ) {
    }

    /**
     * Cron entry point: queues pages while a backfill is running, within the run's time budget.
     *
     * @return void
     */
    public function execute(): void
    {
        $started = time();
        while ($this->state()['status'] === 'running' && time() - $started < self::TIME_BUDGET) {
            $this->step();
        }
    }

    /**
     * The backfill's progress (status 'idle' if it has never run).
     *
     * @return array{status: string, phase?: string, cursor?: int, customers?: int, orders?: int, since?: ?string,
     *   started_at?: int, finished_at?: int}
     */
    public function state(): array
    {
        $state = $this->flagManager->getFlagData(self::FLAG);
        return is_array($state) && isset($state['status']) ? $state : ['status' => 'idle'];
    }

    /**
     * Starts a backfill: customers first, then paid orders.
     *
     * @param int $months orders from the last N months; 0 = all
     * @return void
     */
    public function start(int $months): void
    {
        $this->flagManager->saveFlag(self::FLAG, [
            'status' => 'running',
            'phase' => 'customers',
            'cursor' => 0,
            'customers' => 0,
            'orders' => 0,
            'since' => $months > 0 ? gmdate('Y-m-d H:i:s', strtotime('-' . $months . ' months')) : null,
            'started_at' => time(),
        ]);
    }

    /**
     * Stops a running backfill (references already queued are still sent).
     *
     * @return void
     */
    public function cancel(): void
    {
        $state = $this->state();
        if ($state['status'] === 'running') {
            $state['status'] = 'cancelled';
            $this->flagManager->saveFlag(self::FLAG, $state);
        }
    }

    /**
     * Queues one page. Returns the state afterwards.
     *
     * @return array
     */
    public function step(): array
    {
        $state = $this->state();
        if ($state['status'] !== 'running') {
            return $state;
        }
        $state = $state['phase'] === 'customers' ? $this->customers($state) : $this->orders($state);
        $this->flagManager->saveFlag(self::FLAG, $state);
        return $state;
    }

    /**
     * Queues the next page of customers; moves on to orders after the last page.
     *
     * @param array $state
     * @return array the updated state
     */
    private function customers(array $state): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'), ['entity_id', 'store_id', 'website_id'])
                ->where('entity_id > ?', (int) $state['cursor'])
                ->order('entity_id ASC')
                ->limit(self::PAGE)
        );
        foreach ($rows as $row) {
            $storeId = $this->mapper->customerStoreId((int) $row['store_id'], (int) $row['website_id']);
            if ($this->config->syncCustomers($storeId)) {
                $this->outbox->ref($storeId, Outbox::KIND_CUSTOMER, (int) $row['entity_id']);
                $state['customers']++;
            }
            $state['cursor'] = (int) $row['entity_id'];
        }
        if (count($rows) < self::PAGE) {
            $state['phase'] = 'orders';
            $state['cursor'] = 0;
        }
        return $state;
    }

    /**
     * Queues the next page of paid orders (flagged as backfill); finishes after the last page.
     *
     * @param array $state
     * @return array the updated state
     */
    private function orders(array $state): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('sales_order'), ['entity_id', 'store_id'])
            ->where('entity_id > ?', (int) $state['cursor'])
            ->where('state IN (?)', Mapper::PAID_STATES)
            ->order('entity_id ASC')
            ->limit(self::PAGE);
        if (!empty($state['since'])) {
            $select->where('created_at >= ?', $state['since']);
        }
        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $storeId = (int) $row['store_id'];
            if ($this->config->syncOrders($storeId)) {
                $this->outbox->ref($storeId, Outbox::KIND_ORDER, (int) $row['entity_id'], ['backfill' => true]);
                $state['orders']++;
            }
            $state['cursor'] = (int) $row['entity_id'];
        }
        if (count($rows) < self::PAGE) {
            $state['status'] = 'done';
            $state['finished_at'] = time();
        }
        return $state;
    }
}
