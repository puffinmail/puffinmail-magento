<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Math\Random;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Applies a subscription change from PuffinMail (webhook) to Magento's newsletter_subscriber table, so the
 * customer's account, the admin and the checkout show the right status.
 *
 * Written with plain SQL on purpose: it fires no Magento events, so the change isn't echoed back to
 * PuffinMail, and Magento sends no "subscription confirmed/cancelled" emails.
 */
class NewsletterStatus
{
    private const STATUSES = [
        'subscribed' => Subscriber::STATUS_SUBSCRIBED,
        'pending' => Subscriber::STATUS_NOT_ACTIVE,
        'unsubscribed' => Subscriber::STATUS_UNSUBSCRIBED,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Mapper $mapper,
        private readonly Random $random
    ) {
    }

    /**
     * @param string $status subscribed | pending | unsubscribed
     * @return bool whether anything changed
     */
    public function apply(int $storeId, string $email, string $status): bool
    {
        if (!isset(self::STATUSES[$status]) || $email === '') {
            return false;
        }
        $newStatus = self::STATUSES[$status];
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('newsletter_subscriber');
        $now = gmdate('Y-m-d H:i:s');
        // Newsletter subscriptions are per website in 2.4.
        $storeIds = $this->mapper->websiteStoreIds($storeId);

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['subscriber_id', 'subscriber_status'])
                ->where('subscriber_email = ?', $email)
                ->where('store_id IN (?)', $storeIds)
        );
        if ($rows) {
            $ids = array_column(
                array_filter($rows, static fn (array $row) => (int) $row['subscriber_status'] !== $newStatus),
                'subscriber_id'
            );
            if (!$ids) {
                return false;
            }
            $connection->update(
                $table,
                ['subscriber_status' => $newStatus, 'change_status_at' => $now],
                ['subscriber_id IN (?)' => $ids]
            );
            return true;
        }

        if ($newStatus !== Subscriber::STATUS_SUBSCRIBED) {
            return false; // Never subscribed in Magento; nothing to record.
        }
        $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        $customerId = (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'), ['entity_id'])
                ->where('email = ?', $email)
                ->where('website_id = ?', $websiteId)
                ->limit(1)
        );
        $connection->insert($table, [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'subscriber_email' => $email,
            'subscriber_status' => $newStatus,
            'subscriber_confirm_code' => $this->random->getRandomString(32),
            'change_status_at' => $now,
        ]);
        return true;
    }
}
