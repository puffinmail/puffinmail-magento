<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Observer;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PuffinMail\LifeCycle\Model\Connection;
use PuffinMail\LifeCycle\Model\Logger;
use PuffinMail\LifeCycle\Model\Outbox;

/**
 * Queues a product reference for every connected store view in the product's websites, on product save and on
 * stock changes (cataloginventory_stock_item_save_after carries a product_id). Cron builds the catalogue payload.
 */
class ProductSaved implements ObserverInterface
{
    /**
     * ProductSaved constructor.
     *
     * @param Connection $connection
     * @param Outbox $outbox
     * @param Configurable $configurable
     * @param Logger $logger
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly Configurable $configurable,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queues references for the product (and its configurable parents) in the matching connected store views.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getData('product');
            $productId = $product instanceof Product
                ? (int) $product->getId()
                : (int) $observer->getEvent()->getData('item')?->getProductId();
            if ($productId <= 0) {
                return;
            }
            // A configurable product's child (a size or colour) changes the parent's price and stock: the parent
            // is what emails show, so refresh it too.
            $productIds = array_unique(
                [$productId, ...array_map('intval', $this->configurable->getParentIdsByChild($productId))]
            );
            $websiteIds = $product instanceof Product ? array_map('intval', (array) $product->getWebsiteIds()) : [];
            foreach ($this->connection->connectedStores() as $store) {
                if (!$websiteIds || in_array((int) $store->getWebsiteId(), $websiteIds, true)) {
                    foreach ($productIds as $id) {
                        $this->outbox->ref((int) $store->getId(), Outbox::KIND_PRODUCT, $id);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Queuing a product for PuffinMail failed: ' . $e->getMessage());
        }
    }
}
