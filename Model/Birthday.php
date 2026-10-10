<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Api\Client;

/**
 * Cron (hourly 09:00-20:00, so a missed run catches up the same day): sends `customer.birthday` for active
 * customers whose date of birth is today in the store view's timezone, with an optional coupon. PuffinMail
 * emails it with the Birthday store email. Feb 29 birthdays are
 * celebrated on Feb 28 in other years; puffinmail_birthday makes it once a year.
 */
class Birthday
{
    public const EVENT = 'customer.birthday';

    private const TABLE = 'puffinmail_birthday';

    /**
     * Birthday constructor.
     *
     * @param Config $config
     * @param Connection $connection
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param TimezoneInterface $timezone
     * @param Outbox $outbox
     * @param Mapper $mapper
     * @param CouponProvider $couponProvider
     * @param StoreEmulation $emulation
     * @param Client $client
     * @param Logger $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly Outbox $outbox,
        private readonly Mapper $mapper,
        private readonly CouponProvider $couponProvider,
        private readonly StoreEmulation $emulation,
        private readonly Client $client,
        private readonly Logger $logger
    ) {
    }

    /**
     * Cron entry point: queues today's birthday events.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->run();
    }

    /**
     * Queues birthday events for today in every store view where birthday emails are on.
     *
     * @param bool $dryRun
     * @return string[] one line per event queued (or that would be, when $dryRun)
     */
    public function run(bool $dryRun = false): array
    {
        $report = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->isSetFlag('birthday/enabled', $storeId)) {
                continue;
            }
            if (!$this->connection->isConnected($storeId)) {
                $report[] = sprintf('[%s] skipped: not connected to PuffinMail', $store->getCode());
                continue;
            }

            $today = $this->timezone->scopeDate($storeId);
            $year = (int) $today->format('Y');
            foreach ($this->customersBornOn($today, $year) as $customer) {
                if ($this->mapper->customerStoreId(
                    (int) $customer['store_id'],
                    (int) $customer['website_id']
                ) !== $storeId) {
                    continue;
                }
                $line = sprintf(
                    '[%s] birthday customer #%d -> %s',
                    $store->getCode(),
                    $customer['entity_id'],
                    $customer['email']
                );
                if ($dryRun || $this->emulation->run($storeId, fn () => $this->queue($store, $customer, $year))) {
                    $report[] = $line;
                }
            }
        }
        return $report;
    }

    /**
     * Sends the birthday event for a customer to $to straight away.
     *
     * It isn't recorded and no coupon is generated (a configured coupon shows as TESTCODE).
     *
     * @param int $customerId
     * @param string $to
     * @return array the API's result for the event
     * @throws \InvalidArgumentException if the customer doesn't exist
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \PuffinMail\LifeCycle\Model\Api\ApiException
     */
    public function sendTest(int $customerId, string $to): array
    {
        $connection = $this->resource->getConnection();
        $customer = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'))
                ->where('entity_id = ?', $customerId)
        );
        if (!$customer) {
            throw new \InvalidArgumentException('Customer #' . $customerId . ' not found');
        }
        $store = $this->storeManager->getStore(
            $this->mapper->customerStoreId((int) $customer['store_id'], (int) $customer['website_id'])
        );
        $storeId = (int) $store->getId();

        $event = $this->emulation->run($storeId, fn () => $this->buildEvent(
            $store,
            $customer,
            (int) date('Y'),
            $this->couponProvider->create((int) $this->config->getValue('birthday/coupon_rule', $storeId), '', true)
        ));
        $event['id'] = 'test:' . $event['id'] . ':' . time();
        $event['email'] = $to;
        $event['notify'] = 'always';

        $response = $this->client->sendEvents($storeId, [$event]);
        return (array) ($response['results'][0] ?? []);
    }

    /**
     * Records this year's birthday for the customer (once only), then queues the event with any coupon.
     *
     * @param StoreInterface $store
     * @param array $customer
     * @param int $year
     * @return bool whether the event was queued
     */
    private function queue(StoreInterface $store, array $customer, int $year): bool
    {
        $storeId = (int) $store->getId();
        $customerId = (int) $customer['entity_id'];
        $connection = $this->resource->getConnection();
        try {
            $connection->insert($this->table(), [
                'customer_id' => $customerId,
                'year' => $year,
                'store_id' => $storeId,
                'sent_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (DuplicateException $e) {
            return false; // Already sent this year (or another run got there first).
        }

        try {
            $coupon = $this->couponProvider->create((int) $this->config->getValue('birthday/coupon_rule', $storeId));
            if ($coupon !== null) {
                $connection->update(
                    $this->table(),
                    ['coupon_code' => $coupon['code']],
                    ['customer_id = ?' => $customerId, 'year = ?' => $year]
                );
            }
            $this->outbox->event($storeId, $this->buildEvent($store, $customer, $year, $coupon));
            return true;
        } catch (\Throwable $e) {
            $connection->delete($this->table(), ['customer_id = ?' => $customerId, 'year = ?' => $year]);
            $this->logger->error(sprintf('Birthday event for customer #%d failed: %s', $customerId, $e->getMessage()));
            return false;
        }
    }

    /**
     * The birthday event for a customer: first name, coupon and store details.
     *
     * @param StoreInterface $store
     * @param array $customer
     * @param int $year
     * @param array|null $coupon
     * @return array
     */
    private function buildEvent(StoreInterface $store, array $customer, int $year, ?array $coupon): array
    {
        $customerId = (int) $customer['entity_id'];
        return $this->mapper->extend([
            'type' => self::EVENT,
            'id' => 'birthday:' . $customerId . ':' . $year,
            'email' => (string) $customer['email'],
            'occurred_at' => gmdate('c'),
            'notify' => $this->config->getNotify('birthday', (int) $store->getId()),
            'properties' => [
                'first_name' => trim((string) $customer['firstname']),
                'coupon' => $coupon,
                'coupon_code' => $coupon['code'] ?? '',
            ] + $this->mapper->storeProperties($store),
        ], $store, ['customer_id' => $customerId]);
    }

    /**
     * Active customers born on today's day and month who haven't had this year's event.
     *
     * @param \DateTimeInterface $today
     * @param int $year
     * @return array
     */
    private function customersBornOn(\DateTimeInterface $today, int $year): array
    {
        $days = [$today->format('m-d')];
        if ($days[0] === '02-28' && !(bool) $today->format('L')) {
            $days[] = '02-29';
        }

        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from(
                    ['c' => $this->resource->getTableName('customer_entity')],
                    ['entity_id', 'email', 'firstname', 'store_id', 'website_id']
                )
                ->joinLeft(
                    ['b' => $this->table()],
                    $connection->quoteInto('b.customer_id = c.entity_id AND b.year = ?', $year),
                    []
                )
                ->where('c.is_active = 1')
                ->where('c.dob IS NOT NULL')
                ->where("DATE_FORMAT(c.dob, '%m-%d') IN (?)", $days)
                ->where('b.customer_id IS NULL')
        );
    }

    /**
     * The puffinmail_birthday table name, with any prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
