<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Math\Random;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Api\Client;

/**
 * Abandoned carts, straight from Magento's quotes. Cron (every 5 min) sends, per active cart that has an email:
 *  - `cart.abandoned` once the cart has been idle for the first delay (minutes);
 *  - `cart.abandoned.reminder` the second delay (hours) after the first reminder and the last cart activity.
 * PuffinMail emails each with the workspace's transactional notification of the same name (cart_abandoned,
 * cart_abandoned_reminder), using the event's properties as template variables (items, restore_url, coupon).
 *
 * Magento owns the timing, so a cart that's bought or emptied never gets a reminder. Skipped: carts older than
 * max_age_days or below min_subtotal, an address's older carts, addresses that ordered since the cart was
 * last touched, and addresses reminded about another cart in the last 20 h. Whether the address may be
 * emailed at all (newsletter subscriber or not) is PuffinMail's call, per the "Send reminders to" setting.
 *
 * State per quote lives in puffinmail_cart. A paid order from the quote marks it recovered and, after a
 * reminder, sends `cart.recovered`.
 */
class AbandonedCart
{
    public const EVENT_FIRST = 'cart.abandoned';
    public const EVENT_SECOND = 'cart.abandoned.reminder';
    public const EVENT_RECOVERED = 'cart.recovered';

    private const TABLE = 'puffinmail_cart';
    private const OTHER_CART_COOLDOWN_HOURS = 20;
    private const MIN_FIRST_DELAY_MINUTES = 15;
    private const BATCH_LIMIT = 3000;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Outbox $outbox,
        private readonly Mapper $mapper,
        private readonly CouponProvider $couponProvider,
        private readonly StoreEmulation $emulation,
        private readonly Client $client,
        private readonly Random $random,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        $this->run();
    }

    /**
     * @return string[] one line per reminder queued (or that would be, when $dryRun)
     */
    public function run(bool $dryRun = false): array
    {
        $report = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->config->isSetFlag('abandoned_cart/enabled', $storeId)) {
                continue;
            }
            if (!$this->connection->isConnected($storeId)) {
                $report[] = sprintf('[%s] skipped: not connected to PuffinMail', $store->getCode());
                continue;
            }
            $report = array_merge($report, $this->emulation->run($storeId, fn () => $this->runStore($store, $dryRun)));
        }
        return $report;
    }

    /**
     * Sends reminder $step for a quote to $to straight away, without touching cart state or generating a
     * coupon (a configured coupon shows as TESTCODE). The event id is unique, so it always sends.
     *
     * @return array the API's result for the event
     */
    public function sendTest(int $quoteId, string $to, int $step = 1): array
    {
        $connection = $this->resource->getConnection();
        $quote = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName('quote'))->where('entity_id = ?', $quoteId)
        );
        if (!$quote) {
            throw new \InvalidArgumentException('Quote #' . $quoteId . ' not found');
        }
        $store = $this->storeManager->getStore((int) $quote['store_id']);
        $step = $step === 2 ? 2 : 1;

        $event = $this->emulation->run((int) $store->getId(), fn () => $this->buildEvent(
            $store,
            $quote,
            $step,
            $this->couponProvider->create($this->couponRule($step, (int) $store->getId()), '', true),
            $this->restoreUrl($store, 'test')
        ));
        $event['id'] = 'test:' . $event['id'] . ':' . time();
        $event['email'] = $to;
        $event['notify'] = 'always';

        $response = $this->client->sendEvents((int) $store->getId(), [$event]);
        return (array) ($response['results'][0] ?? []);
    }

    /**
     * Called when an order is synced: an order from a tracked cart recovers it, and after a reminder that
     * also sends `cart.recovered` (the reminder worked).
     */
    public function orderPlaced(Order $order): void
    {
        $quoteId = (int) $order->getQuoteId();
        if ($quoteId <= 0 || in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_PENDING_PAYMENT], true)) {
            return;
        }
        $connection = $this->resource->getConnection();
        $cart = $this->get($quoteId);
        if (!$cart || $cart['status'] === 'recovered') {
            return;
        }
        $connection->update(
            $this->table(),
            ['status' => 'recovered', 'order_id' => (int) $order->getId()],
            ['quote_id = ?' => $quoteId]
        );
        if ((int) $cart['reminders_sent'] === 0) {
            return;
        }

        $this->outbox->event((int) $order->getStoreId(), $this->mapper->extend([
            'type' => self::EVENT_RECOVERED,
            'id' => 'cart:' . $quoteId,
            'email' => $order->getCustomerEmail(),
            'occurred_at' => gmdate('c'),
            'currency' => $order->getOrderCurrencyCode(),
            'properties' => [
                'cart_id' => $quoteId,
                'order_id' => (int) $order->getId(),
                'order_number' => (string) $order->getIncrementId(),
                'total' => round((float) $order->getGrandTotal(), 2),
                'reminders_sent' => (int) $cart['reminders_sent'],
                'clicked' => !empty($cart['clicked_at']),
                'coupon_used' => $cart['coupon_code'] !== null && $cart['coupon_code'] !== ''
                    && strcasecmp((string) $order->getCouponCode(), (string) $cart['coupon_code']) === 0,
            ],
        ], $order->getStore(), ['quote_id' => $quoteId, 'order_id' => (int) $order->getId()]));
    }

    /** puffinmail_cart row for a restore-link token. */
    public function getByToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }
        $connection = $this->resource->getConnection();
        return $connection->fetchRow(
            $connection->select()->from($this->table())->where('token = ?', $token)
        ) ?: null;
    }

    public function markClicked(int $quoteId): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['clicked_at' => gmdate('Y-m-d H:i:s')],
            ['quote_id = ?' => $quoteId, 'clicked_at IS NULL']
        );
    }

    // -------------------------------------------------------------------------------------------------------

    private function runStore(StoreInterface $store, bool $dryRun): array
    {
        $storeId = (int) $store->getId();
        $report = [];
        $seen = [];
        $firstDelay = max(self::MIN_FIRST_DELAY_MINUTES, (int) $this->config->getValue('abandoned_cart/first_delay', $storeId)) * 60;
        $secondDelay = $this->config->isSetFlag('abandoned_cart/second_enabled', $storeId)
            ? max(1, (int) $this->config->getValue('abandoned_cart/second_delay', $storeId)) * 3600
            : null;

        foreach ($this->candidates($storeId) as $row) {
            $quoteId = (int) $row['entity_id'];
            $email = $this->outbox->normalize((string) $row['customer_email']);
            // Rows are newest first: only an address's latest cart counts, so someone still shopping in a fresh
            // cart isn't reminded about an older one.
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $sent = (int) ($row['reminders_sent'] ?? 0);
            $idleSince = strtotime($row['updated_at'] . ' UTC');
            if ($sent === 0) {
                $step = 1;
                $due = time() - $idleSince >= $firstDelay;
            } elseif ($sent === 1 && $secondDelay !== null) {
                $step = 2;
                $due = time() - max($idleSince, strtotime($row['first_sent_at'] . ' UTC')) >= $secondDelay;
            } else {
                continue;
            }
            if (!$due) {
                continue;
            }

            if ($this->hasOrderedSince($email, (string) $row['updated_at'], $storeId)) {
                if (!$dryRun && $sent > 0) {
                    $this->resource->getConnection()->update($this->table(), ['status' => 'recovered'], ['quote_id = ?' => $quoteId]);
                }
                continue;
            }
            if ($this->remindedRecently($email, $quoteId)) {
                continue;
            }

            $line = sprintf('[%s] cart #%d reminder %d -> %s', $store->getCode(), $quoteId, $step, $email);
            if ($dryRun || $this->queue($store, $row, $email, $step)) {
                $report[] = $line;
            }
        }
        return $report;
    }

    /**
     * Reserves the reminder in puffinmail_cart (so overlapping runs can't both send it), then queues the event.
     */
    private function queue(StoreInterface $store, array $quote, string $email, int $step): bool
    {
        $storeId = (int) $store->getId();
        $quoteId = (int) $quote['entity_id'];
        $connection = $this->resource->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $cart = $this->get($quoteId);
        if (!$cart) {
            $cart = [
                'quote_id' => $quoteId,
                'store_id' => $storeId,
                'email' => $email,
                'token' => $this->random->getRandomString(32),
                'reminders_sent' => 0,
                'status' => 'active',
            ];
            $connection->insertOnDuplicate($this->table(), $cart, ['email']);
            $cart = $this->get($quoteId) ?? $cart;
        }

        $update = ['reminders_sent' => $step, 'last_sent_at' => $now, 'email' => $email];
        if ($step === 1) {
            $update['first_sent_at'] = $now;
        }
        $reserved = $connection->update($this->table(), $update, [
            'quote_id = ?' => $quoteId,
            'reminders_sent = ?' => $step - 1,
            'status = ?' => 'active',
        ]);
        if ($reserved !== 1) {
            return false;
        }

        try {
            $coupon = $this->couponProvider->create($this->couponRule($step, $storeId));
            if ($coupon !== null) {
                $connection->update($this->table(), ['coupon_code' => $coupon['code']], ['quote_id = ?' => $quoteId]);
            }
            $event = $this->buildEvent($store, $quote, $step, $coupon, $this->restoreUrl($store, (string) $cart['token']));
            $event['email'] = $email;
            $this->outbox->event($storeId, $event);
            return true;
        } catch (\Throwable $e) {
            $connection->update(
                $this->table(),
                ['reminders_sent' => $step - 1] + ($step === 1 ? ['first_sent_at' => null] : []),
                ['quote_id = ?' => $quoteId]
            );
            $this->logger->error(sprintf('Abandoned cart #%d reminder %d failed: %s', $quoteId, $step, $e->getMessage()));
            return false;
        }
    }

    private function buildEvent(StoreInterface $store, array $quote, int $step, ?array $coupon, string $restoreUrl): array
    {
        $storeId = (int) $store->getId();
        $quoteId = (int) $quote['entity_id'];
        return $this->mapper->extend([
            'type' => $step === 1 ? self::EVENT_FIRST : self::EVENT_SECOND,
            'id' => 'cart:' . $quoteId,
            'email' => (string) $quote['customer_email'],
            'occurred_at' => gmdate('c'),
            'currency' => (string) ($quote['quote_currency_code'] ?: $store->getCurrentCurrencyCode()),
            'notify' => $this->config->getNotify('abandoned_cart', $storeId),
            'properties' => [
                'cart_id' => $quoteId,
                'reminder' => $step,
                'first_name' => trim((string) $quote['customer_firstname']),
                'restore_url' => $restoreUrl,
                'coupon' => $coupon,
                'coupon_code' => $coupon['code'] ?? '',
            ] + $this->mapper->cartProperties($quoteId, $storeId) + $this->mapper->storeProperties($store),
        ], $store, ['quote_id' => $quoteId, 'customer_id' => (int) $quote['customer_id']]);
    }

    private function restoreUrl(StoreInterface $store, string $token): string
    {
        return $store->getUrl('puffinmail/cart/restore', [
            '_secure' => true,
            '_nosid' => true,
            '_query' => ['t' => $token],
        ]);
    }

    private function couponRule(int $step, int $storeId): int
    {
        return (int) $this->config->getValue('abandoned_cart/' . ($step === 1 ? 'first' : 'second') . '_coupon_rule', $storeId);
    }

    private function candidates(int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $maxAgeDays = max(1, (int) $this->config->getValue('abandoned_cart/max_age_days', $storeId));

        return $connection->fetchAll(
            $connection->select()
                ->from(
                    ['q' => $this->resource->getTableName('quote')],
                    ['entity_id', 'customer_id', 'customer_email', 'customer_firstname', 'updated_at', 'quote_currency_code']
                )
                ->joinLeft(
                    ['c' => $this->table()],
                    'c.quote_id = q.entity_id',
                    ['reminders_sent', 'first_sent_at', 'status']
                )
                ->where('q.store_id = ?', $storeId)
                ->where('q.is_active = 1')
                ->where('q.items_count > 0')
                ->where("q.customer_email IS NOT NULL AND q.customer_email <> ''")
                ->where('q.updated_at >= ?', gmdate('Y-m-d H:i:s', time() - $maxAgeDays * 86400))
                ->where('q.base_subtotal >= ?', (float) $this->config->getValue('abandoned_cart/min_subtotal', $storeId))
                ->where("c.status IS NULL OR c.status = 'active'")
                ->order('q.updated_at DESC')
                ->limit(self::BATCH_LIMIT)
        );
    }

    private function hasOrderedSince(string $email, string $since, int $storeId): bool
    {
        $connection = $this->resource->getConnection();
        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), ['entity_id'])
                ->where('created_at >= ?', $since)
                ->where('customer_email = ?', $email)
                ->where('store_id IN (?)', $this->mapper->websiteStoreIds($storeId))
                ->where('state <> ?', Order::STATE_CANCELED)
                ->limit(1)
        );
    }

    /** Whether the address got a reminder for a *different* cart recently. */
    private function remindedRecently(string $email, int $quoteId): bool
    {
        $connection = $this->resource->getConnection();
        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->table(), ['quote_id'])
                ->where('email = ?', $email)
                ->where('quote_id <> ?', $quoteId)
                ->where('last_sent_at >= ?', gmdate('Y-m-d H:i:s', time() - self::OTHER_CART_COOLDOWN_HOURS * 3600))
                ->limit(1)
        );
    }

    private function get(int $quoteId): ?array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchRow(
            $connection->select()->from($this->table())->where('quote_id = ?', $quoteId)
        ) ?: null;
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
