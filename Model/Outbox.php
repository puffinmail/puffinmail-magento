<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Serialize\Serializer\Json;
use PuffinMail\LifeCycle\Model\Api\ApiException;

/**
 * Outbox for PuffinMail API calls (table puffinmail_outbox). Shop code only writes a row, so storefront and
 * admin requests never wait on the network; OutboxProcessor sends rows in batches every minute, retries
 * failures with backoff and keeps permanently failed rows for the admin status panel.
 *
 * Rows are de-duplicated per store view: queuing the same contact twice merges the two updates, queuing the
 * same event (type + id) replaces it, and a reference (customer/order/creditmemo) is expanded only once.
 */
class Outbox
{
    public const KIND_CONTACT = 'contact';
    public const KIND_EVENT = 'event';
    public const KIND_UNSUBSCRIBE = 'unsubscribe';
    public const KIND_DELETE = 'delete';
    public const KIND_CUSTOMER = 'customer';
    public const KIND_ORDER = 'order';
    public const KIND_CREDITMEMO = 'creditmemo';
    /** Product reference (expanded in cron) and the catalogue payload it becomes. */
    public const KIND_PRODUCT = 'product';
    public const KIND_CATALOG = 'catalog';

    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    private const TABLE = 'puffinmail_outbox';
    private const MAX_ATTEMPTS = 8;
    private const MAX_DELAY = 6 * 3600;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json
    ) {
    }

    /**
     * Queues a contact upsert (Store API POST /contacts). Pending updates for the same email are merged so the
     * latest details win and an earlier subscription isn't lost.
     */
    public function contact(int $storeId, array $contact): void
    {
        $email = $this->normalize((string) ($contact['email'] ?? ''));
        if ($email === '') {
            return;
        }
        $contact['email'] = $email;
        if (isset($contact['subscription'])) {
            // A newer opt-in overrides an unsubscribe that hasn't been sent yet.
            $this->deletePending($this->key($storeId, self::KIND_UNSUBSCRIBE, $email));
        }
        $this->put($storeId, self::KIND_CONTACT, $this->key($storeId, self::KIND_CONTACT, $email), $contact, true);
    }

    /** Queues a catalogue product (POST /products). Re-queuing the same product replaces the pending one. */
    public function catalog(int $storeId, array $product): void
    {
        if (empty($product['id'])) {
            return;
        }
        $this->put($storeId, self::KIND_CATALOG, $this->key($storeId, self::KIND_CATALOG, (string) $product['id']), $product, false);
    }

    /** Queues an event. Re-queuing the same type and id replaces the pending one. */
    public function event(int $storeId, array $event): void
    {
        $event['email'] = $this->normalize((string) ($event['email'] ?? ''));
        if ($event['email'] === '' || empty($event['type']) || empty($event['id'])) {
            return;
        }
        $key = $this->key($storeId, self::KIND_EVENT, $event['type'] . ':' . $event['id']);
        $this->put($storeId, self::KIND_EVENT, $key, $event, false);
    }

    public function unsubscribe(int $storeId, string $email): void
    {
        $email = $this->normalize($email);
        if ($email === '') {
            return;
        }
        $this->stripPendingSubscription($storeId, $email);
        $this->put($storeId, self::KIND_UNSUBSCRIBE, $this->key($storeId, self::KIND_UNSUBSCRIBE, $email), ['email' => $email], false);
    }

    /** Queues a contact erasure and drops anything still waiting to be sent for that email. */
    public function deleteContact(int $storeId, string $email): void
    {
        $email = $this->normalize($email);
        if ($email === '') {
            return;
        }
        $connection = $this->resource->getConnection();
        foreach ([self::KIND_CONTACT, self::KIND_UNSUBSCRIBE] as $kind) {
            $connection->delete($this->table(), ['dedupe_key = ?' => $this->key($storeId, $kind, $email)]);
        }
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->table(), ['outbox_id', 'payload'])
                ->where('store_id = ?', $storeId)
                ->where('kind = ?', self::KIND_EVENT)
        );
        foreach ($rows as $row) {
            $payload = $this->decode($row['payload']);
            if (($payload['email'] ?? '') === $email) {
                $this->remove((int) $row['outbox_id']);
            }
        }
        $this->put($storeId, self::KIND_DELETE, $this->key($storeId, self::KIND_DELETE, $email), ['email' => $email], false);
    }

    /**
     * Queues a reference that OutboxProcessor turns into contacts/events when it runs, so building the payload
     * (order stats, product images) happens in cron rather than in the request that saved the entity.
     *
     * @param string $kind customer | order | creditmemo | product
     * @param array $extra stored with the reference (e.g. ['backfill' => true])
     */
    public function ref(int $storeId, string $kind, int $id, array $extra = []): void
    {
        if ($id <= 0) {
            return;
        }
        // A backfill reference stays separate from a live one for the same entity, so neither changes the other.
        $keyId = $id . (empty($extra['backfill']) ? '' : ':backfill');
        $this->put($storeId, $kind, $this->key($storeId, $kind, $keyId), ['id' => $id] + $extra, true);
    }

    /**
     * Rows of $kind that are due for a store view, oldest first.
     *
     * @return array<int, array{outbox_id: string, payload: array, attempts: string}>
     */
    public function due(int $storeId, string $kind, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->table(), ['outbox_id', 'payload', 'attempts'])
                ->where('store_id = ?', $storeId)
                ->where('kind = ?', $kind)
                ->where('status = ?', self::STATUS_PENDING)
                ->where('next_attempt_at <= ?', $this->now())
                ->order('outbox_id ASC')
                ->limit($limit)
        );
        foreach ($rows as &$row) {
            $row['payload'] = $this->decode($row['payload']);
        }
        return $rows;
    }

    /** @return int[] store views with rows due now */
    public function dueStoreIds(): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($this->table(), ['store_id'])
                ->where('status = ?', self::STATUS_PENDING)
                ->where('next_attempt_at <= ?', $this->now())
        ));
    }

    public function remove(int $outboxId): void
    {
        $this->resource->getConnection()->delete($this->table(), ['outbox_id = ?' => $outboxId]);
    }

    public function markFailed(int $outboxId, string $error): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['status' => self::STATUS_FAILED, 'last_error' => $error, 'updated_at' => $this->now()],
            ['outbox_id = ?' => $outboxId]
        );
    }

    /**
     * After a failed call: retryable errors are rescheduled with exponential backoff (up to 8 attempts), the rest
     * fail permanently. A 401 is handled by the caller (the whole store view pauses), not here.
     */
    public function handleError(array $rows, ApiException $error): void
    {
        $connection = $this->resource->getConnection();
        foreach ($rows as $row) {
            $attempts = (int) $row['attempts'] + 1;
            if (!$error->isRetryable() || $attempts >= self::MAX_ATTEMPTS) {
                $this->markFailed((int) $row['outbox_id'], $error->getMessage());
                continue;
            }
            $delay = max($error->getRetryAfter(), min(self::MAX_DELAY, 60 * (2 ** $attempts)));
            $connection->update(
                $this->table(),
                [
                    'attempts' => $attempts,
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                    'last_error' => $error->getMessage(),
                    'updated_at' => $this->now(),
                ],
                ['outbox_id = ?' => (int) $row['outbox_id']]
            );
        }
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function stats(?int $storeId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table(), ['status', 'n' => 'COUNT(*)'])
            ->group('status');
        if ($storeId !== null) {
            $select->where('store_id = ?', $storeId);
        }
        return array_map('intval', $connection->fetchPairs($select)) + [self::STATUS_PENDING => 0, self::STATUS_FAILED => 0];
    }

    /** @return array<int, array{store_id: string, kind: string, dedupe_key: string, last_error: string, updated_at: string}> */
    public function recentFailures(int $limit = 10): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->table(), ['store_id', 'kind', 'dedupe_key', 'last_error', 'updated_at'])
                ->where('status = ?', self::STATUS_FAILED)
                ->order('updated_at DESC')
                ->limit($limit)
        );
    }

    public function retryFailed(): int
    {
        return $this->resource->getConnection()->update(
            $this->table(),
            ['status' => self::STATUS_PENDING, 'attempts' => 0, 'next_attempt_at' => $this->now()],
            ['status = ?' => self::STATUS_FAILED]
        );
    }

    /** Failed rows are kept for a month for the status panel, then dropped. */
    public function purgeOldFailures(): void
    {
        $this->resource->getConnection()->delete($this->table(), [
            'status = ?' => self::STATUS_FAILED,
            'updated_at < ?' => gmdate('Y-m-d H:i:s', time() - 30 * 86400),
        ]);
    }

    public function normalize(string $email): string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function put(int $storeId, string $kind, string $dedupeKey, array $payload, bool $merge): void
    {
        $connection = $this->resource->getConnection();
        $now = $this->now();
        $existing = $connection->fetchRow(
            $connection->select()
                ->from($this->table(), ['outbox_id', 'payload', 'status'])
                ->where('dedupe_key = ?', $dedupeKey)
        );

        if (!$existing) {
            try {
                $connection->insert($this->table(), [
                    'store_id' => $storeId,
                    'kind' => $kind,
                    'dedupe_key' => $dedupeKey,
                    'payload' => $this->json->serialize($payload),
                    'status' => self::STATUS_PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return;
            } catch (DuplicateException $e) {
                // Inserted by a concurrent request: update it below.
                $existing = $connection->fetchRow(
                    $connection->select()
                        ->from($this->table(), ['outbox_id', 'payload', 'status'])
                        ->where('dedupe_key = ?', $dedupeKey)
                );
                if (!$existing) {
                    return;
                }
            }
        }

        if ($merge && $existing['status'] === self::STATUS_PENDING) {
            $payload = array_replace($this->decode($existing['payload']), $payload);
        }
        $connection->update(
            $this->table(),
            [
                'payload' => $this->json->serialize($payload),
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'last_error' => null,
                'updated_at' => $now,
            ],
            ['outbox_id = ?' => (int) $existing['outbox_id']]
        );
    }

    private function stripPendingSubscription(int $storeId, string $email): void
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table(), ['outbox_id', 'payload'])
                ->where('dedupe_key = ?', $this->key($storeId, self::KIND_CONTACT, $email))
        );
        if ($row) {
            $payload = $this->decode($row['payload']);
            unset($payload['subscription']);
            $connection->update(
                $this->table(),
                ['payload' => $this->json->serialize($payload)],
                ['outbox_id = ?' => (int) $row['outbox_id']]
            );
        }
    }

    /**
     * Drops a queued row, pending or failed, so "Retry failed" can't later undo a newer consent change.
     */
    private function deletePending(string $dedupeKey): void
    {
        $this->resource->getConnection()->delete($this->table(), ['dedupe_key = ?' => $dedupeKey]);
    }

    private function key(int $storeId, string $kind, string $id): string
    {
        $key = $storeId . '|' . $kind . '|' . $id;
        return strlen($key) <= 191 ? $key : $storeId . '|' . $kind . '|sha1:' . sha1($id);
    }

    private function decode(string $payload): array
    {
        try {
            $data = $this->json->unserialize($payload);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
