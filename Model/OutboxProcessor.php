<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Creditmemo;
use PuffinMail\LifeCycle\Model\Api\ApiException;
use PuffinMail\LifeCycle\Model\Api\Client;

/**
 * Cron (every minute): for each store view with work due,
 *  1. expands references (customer/order/creditmemo saved since the last run) into contacts and events;
 *  2. sends contacts (100 per call), events (50 per call), catalogue products (100 per call), unsubscribes and
 *     deletions.
 *
 * Per-item rejections (e.g. an invalid email) fail only that row. Network/5xx errors reschedule the batch
 * with backoff. A 401 pauses the store view (nothing is lost) until the connection is refreshed.
 */
class OutboxProcessor
{
    private const TIME_BUDGET = 45;
    private const REF_BATCH = 50;

    /**
     * OutboxProcessor constructor.
     *
     * @param Outbox $outbox
     * @param Client $client
     * @param Connection $connection
     * @param Config $config
     * @param Mapper $mapper
     * @param OrderSync $orderSync
     * @param StoreEmulation $emulation
     * @param CustomerRepositoryInterface $customerRepository
     * @param OrderFactory $orderFactory
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param ProductRepositoryInterface $productRepository
     * @param Logger $logger
     */
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Client $client,
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Mapper $mapper,
        private readonly OrderSync $orderSync,
        private readonly StoreEmulation $emulation,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderFactory $orderFactory,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Logger $logger
    ) {
    }

    /**
     * Cron entry point: processes the outbox.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->run();
    }

    /**
     * Expands references and sends due rows for each store view, within the run's time budget.
     *
     * @return string[] report lines
     */
    public function run(): array
    {
        $started = time();
        $report = [];
        foreach ($this->outbox->dueStoreIds() as $storeId) {
            if (!$this->connection->ensureConnected($storeId)) {
                $report[] = sprintf(
                    'store view %d: not connected, %d row(s) waiting',
                    $storeId,
                    $this->outbox->stats($storeId)['pending']
                );
                continue;
            }
            $counts = ['expanded' => 0, 'sent' => 0];
            do {
                $expanded = $this->emulation->run($storeId, fn () => $this->expand($storeId));
                $sent = $this->flush($storeId);
                $counts['expanded'] += $expanded;
                $counts['sent'] += $sent;
            } while (($expanded + $sent) > 0
                && time() - $started < self::TIME_BUDGET
                && $this->connection->isConnected($storeId)
            );
            $report[] = sprintf(
                'store view %d: %d reference(s) expanded, %d item(s) sent',
                $storeId,
                $counts['expanded'],
                $counts['sent']
            );

            if (time() - $started >= self::TIME_BUDGET) {
                break;
            }
        }
        if (random_int(1, 60) === 1) {
            $this->outbox->purgeOldFailures();
        }
        return $report;
    }

    // -- References ----------------------------------------------------------------------------------------

    /**
     * Turns due references (customer, order, credit memo, product) into contacts, events and catalogue rows.
     *
     * @param int $storeId
     * @return int references handled
     */
    private function expand(int $storeId): int
    {
        $count = 0;
        foreach ([Outbox::KIND_CUSTOMER, Outbox::KIND_ORDER, Outbox::KIND_CREDITMEMO, Outbox::KIND_PRODUCT] as $kind) {
            foreach ($this->outbox->due($storeId, $kind, self::REF_BATCH) as $row) {
                $outboxId = (int) $row['outbox_id'];
                try {
                    $this->expandOne($kind, $row['payload'], $storeId);
                    $this->outbox->remove($outboxId);
                } catch (NoSuchEntityException $e) {
                    $this->outbox->remove($outboxId); // Deleted since it was queued: nothing to send.
                } catch (\Throwable $e) {
                    $this->outbox->markFailed($outboxId, $e->getMessage());
                    $this->logger->error(
                        sprintf('Building %s #%d failed: %s', $kind, $row['payload']['id'] ?? 0, $e->getMessage())
                    );
                }
                $count++;
            }
        }
        return $count;
    }

    /**
     * Expands one reference into what it queues: a contact, order events, a refund event or a catalogue row.
     *
     * @param string $kind
     * @param array $payload
     * @param int $storeId
     * @return void
     * @throws NoSuchEntityException if the entity was deleted since it was queued
     */
    private function expandOne(string $kind, array $payload, int $storeId): void
    {
        $id = (int) ($payload['id'] ?? 0);
        switch ($kind) {
            case Outbox::KIND_CUSTOMER:
                $contact = $this->mapper->contactFromCustomer($id);
                if ($contact !== null) {
                    $customer = $this->customerRepository->getById($id);
                    $this->outbox->contact(
                        $this->mapper->customerStoreId((int) $customer->getStoreId(), (int) $customer->getWebsiteId()),
                        $contact
                    );
                }
                break;
            case Outbox::KIND_ORDER:
                // Loaded fresh: the repository caches per process, and cron runs many jobs in one process.
                $order = $this->orderFactory->create()->load($id);
                if ($order->getId()) {
                    $this->orderSync->sync($order, !empty($payload['backfill']));
                }
                break;
            case Outbox::KIND_CREDITMEMO:
                $creditmemo = $this->creditmemoRepository->get($id);
                if ($creditmemo instanceof Creditmemo) {
                    $this->orderSync->syncRefund($creditmemo);
                }
                break;
            case Outbox::KIND_PRODUCT:
                // Loaded fresh in the store view (prices, URL and stock are per store view).
                $product = $this->productRepository->getById($id, false, $storeId, true);
                if ($product instanceof Product) {
                    $this->outbox->catalog($storeId, $this->mapper->catalogProduct($product, $storeId));
                }
                break;
        }
    }

    // -- Sending -------------------------------------------------------------------------------------------

    /**
     * Sends due contacts, events, catalogue products, unsubscribes and deletions for a store view.
     *
     * @param int $storeId
     * @return int rows attempted (0 if the store view stopped for this run)
     */
    private function flush(int $storeId): int
    {
        try {
            $sent = $this->flushBatch($storeId, Outbox::KIND_CONTACT, Client::CONTACT_BATCH);
            $sent += $this->flushBatch($storeId, Outbox::KIND_EVENT, Client::EVENT_BATCH);
            $sent += $this->flushBatch($storeId, Outbox::KIND_CATALOG, Client::PRODUCT_BATCH);
            $sent += $this->flushSingles($storeId, Outbox::KIND_UNSUBSCRIBE);
            $sent += $this->flushSingles($storeId, Outbox::KIND_DELETE);
            return $sent;
        } catch (StopStoreException $e) {
            return 0;
        }
    }

    /**
     * One batch call. The API answers with a result per item: contacts by email, events by id (and type).
     *
     * @param int $storeId
     * @param string $kind
     * @param int $limit
     * @return int rows attempted
     * @throws StopStoreException
     */
    private function flushBatch(int $storeId, string $kind, int $limit): int
    {
        $rows = $this->outbox->due($storeId, $kind, $limit);
        if ($rows) {
            $this->sendRows($storeId, $kind, $rows);
        }
        return count($rows);
    }

    /**
     * Sends rows in one call (halving on 413), then removes the accepted ones and fails the rejected ones.
     *
     * @param int $storeId
     * @param string $kind
     * @param array $rows
     * @return void
     * @throws StopStoreException
     */
    private function sendRows(int $storeId, string $kind, array $rows): void
    {
        $payloads = array_column($rows, 'payload');
        try {
            $response = match ($kind) {
                Outbox::KIND_CONTACT => $this->client->upsertContacts($storeId, $payloads),
                Outbox::KIND_CATALOG => $this->client->upsertProducts($storeId, $payloads),
                default => $this->client->sendEvents($storeId, $payloads),
            };
        } catch (ApiException $e) {
            if ($e->getCode() === 413 && count($rows) > 1) {
                // Body over the API's 1 MB limit (e.g. large orders): send in halves.
                $half = (int) ceil(count($rows) / 2);
                $this->sendRows($storeId, $kind, array_slice($rows, 0, $half));
                $this->sendRows($storeId, $kind, array_slice($rows, $half));
                return;
            }
            $this->handleError($storeId, $rows, $e);
            return;
        }

        // Results for events that failed validation carry only the id, so match on id too.
        $errors = [];
        foreach ((array) ($response['results'] ?? []) as $result) {
            if (empty($result['ok'])) {
                $errors[$this->resultKey($kind, (array) $result)] = (string) ($result['error'] ?? 'Rejected');
            }
        }
        foreach ($rows as $i => $row) {
            $key = $this->resultKey($kind, $payloads[$i]);
            $error = $errors[$key]
                ?? ($kind === Outbox::KIND_EVENT ? ($errors['|' . ($payloads[$i]['id'] ?? '')] ?? null) : null);
            if ($error !== null) {
                $this->outbox->markFailed((int) $row['outbox_id'], $error);
                $this->logger->warning(sprintf('PuffinMail rejected %s %s: %s', $kind, $key, $error));
            } else {
                $this->outbox->remove((int) $row['outbox_id']);
            }
        }
        $this->logger->debug(sprintf('store view %d: sent %d %s(s)', $storeId, count($rows), $kind));
    }

    /**
     * Unsubscribes and deletions go one call each; a 404 (unknown contact) counts as done.
     *
     * @param int $storeId
     * @param string $kind
     * @return int rows attempted
     * @throws StopStoreException
     */
    private function flushSingles(int $storeId, string $kind): int
    {
        $rows = $this->outbox->due($storeId, $kind, 20);
        foreach ($rows as $row) {
            $email = (string) ($row['payload']['email'] ?? '');
            try {
                if ($kind === Outbox::KIND_UNSUBSCRIBE) {
                    $this->client->unsubscribe($storeId, $email);
                } else {
                    $this->client->deleteContact($storeId, $email);
                }
            } catch (ApiException $e) {
                if ($e->getCode() !== 404) {
                    $this->handleError($storeId, [$row], $e);
                    continue;
                }
            }
            $this->outbox->remove((int) $row['outbox_id']);
        }
        return count($rows);
    }

    /**
     * Handles a failed call: pauses the store view on 401/403, otherwise reschedules or fails the rows.
     *
     * @param int $storeId
     * @param array $rows
     * @param ApiException $e
     * @return void
     * @throws StopStoreException when the store view should stop for this run (key rejected, or PuffinMail down)
     */
    private function handleError(int $storeId, array $rows, ApiException $e): void
    {
        if ($e->isUnauthorized() || $e->getCode() === 403) {
            // Key rejected or PuffinMail account inactive: keep everything and stop until the store view is
            // reconnected.
            $this->connection->markUnauthorized($storeId, $e->getMessage());
            throw new StopStoreException();
        }
        $this->outbox->handleError($rows, $e);
        $this->logger->warning('PuffinMail call failed for store view ' . $storeId . ': ' . $e->getMessage());
        if ($e->isRetryable()) {
            throw new StopStoreException();
        }
    }

    /**
     * The key that matches an API result to its row: email for contacts, id for products, type|id for events.
     *
     * @param string $kind
     * @param array $item
     * @return string
     */
    private function resultKey(string $kind, array $item): string
    {
        return match ($kind) {
            Outbox::KIND_CONTACT => strtolower((string) ($item['email'] ?? '')),
            Outbox::KIND_CATALOG => (string) ($item['id'] ?? ''),
            default => ($item['type'] ?? '') . '|' . ($item['id'] ?? ''),
        };
    }
}
