<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\FlagManager;

/**
 * Cron (every minute): sends each connected store view's whole catalogue once (product references, 200 per run;
 * OutboxProcessor builds and sends them), after which product saves keep it current. Also refreshes connections
 * made before the storefront script existed, so they learn its URL.
 *
 * Restart with `bin/magento puffinmail:catalog --restart` (Console\Command\Catalog).
 */
class CatalogSync
{
    private const FLAG_PREFIX = 'puffinmail_catalog_';
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly FlagManager $flagManager,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->connection->connectedStores() as $store) {
            $storeId = (int) $store->getId();
            try {
                $this->connection->ensureStorefront($storeId);
                $this->step($storeId, (int) $store->getWebsiteId());
            } catch (\Throwable $e) {
                $this->logger->warning('PuffinMail catalogue sync for store view ' . $storeId . ' failed: ' . $e->getMessage());
            }
        }
    }

    /** @return array{status?: string, last_id?: int, products?: int, started_at?: int, finished_at?: int} */
    public function state(int $storeId): array
    {
        $state = $this->flagManager->getFlagData(self::FLAG_PREFIX . $storeId);
        return is_array($state) ? $state : [];
    }

    public function restart(int $storeId): void
    {
        $this->flagManager->saveFlag(self::FLAG_PREFIX . $storeId, ['status' => 'running', 'last_id' => 0, 'products' => 0, 'started_at' => time()]);
    }

    private function step(int $storeId, int $websiteId): void
    {
        $state = $this->state($storeId);
        if (!$state) {
            $this->restart($storeId); // Never synced: start now.
            $state = $this->state($storeId);
        }
        if (($state['status'] ?? '') !== 'running') {
            return;
        }
        // Keyset paging by entity id: stable while products are added or removed.
        $collection = $this->productCollectionFactory->create()
            ->addWebsiteFilter([$websiteId])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]])
            ->addFieldToFilter('entity_id', ['gt' => (int) ($state['last_id'] ?? 0)])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize(self::PAGE_SIZE)
            ->setCurPage(1);
        // Not getAllIds(): it drops the sort order, which keyset paging relies on.
        $ids = [];
        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
        }
        foreach ($ids as $id) {
            $this->outbox->ref($storeId, Outbox::KIND_PRODUCT, $id);
        }
        $state['products'] = (int) ($state['products'] ?? 0) + count($ids);
        if ($ids) {
            $state['last_id'] = end($ids);
        }
        if (count($ids) < self::PAGE_SIZE) {
            $state['status'] = 'done';
            $state['finished_at'] = time();
            $this->logger->info(sprintf('PuffinMail catalogue queued for store view %d: %d products', $storeId, $state['products']));
        }
        $this->flagManager->saveFlag(self::FLAG_PREFIX . $storeId, $state);
    }
}
