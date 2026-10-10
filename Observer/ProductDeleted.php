<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PuffinMail\LifeCycle\Model\Connection;
use PuffinMail\LifeCycle\Model\Logger;
use PuffinMail\LifeCycle\Model\Mapper;
use PuffinMail\LifeCycle\Model\Outbox;

/** A deleted product can't be loaded later: queue its withdrawal now, for every connected store view. */
class ProductDeleted implements ObserverInterface
{
    /**
     * ProductDeleted constructor.
     *
     * @param Connection $connection
     * @param Outbox $outbox
     * @param Mapper $mapper
     * @param Logger $logger
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly Mapper $mapper,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queues the deleted product as withdrawn for each connected store view.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product || !$product->getId()) {
            return;
        }
        try {
            foreach ($this->connection->connectedStores() as $store) {
                $this->outbox->catalog(
                    (int) $store->getId(),
                    $this->mapper->deletedProduct($product, (int) $store->getId())
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Queuing a deleted product for PuffinMail failed: ' . $e->getMessage());
        }
    }
}
