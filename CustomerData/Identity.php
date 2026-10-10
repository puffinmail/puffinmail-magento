<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Connection;

/**
 * Private customer-data section `puffinmail`: the signed-in customer's email with an HMAC of it made with the
 * store's PuffinMail webhook secret, so the storefront script can link website visits to the customer without the
 * visitor being able to claim someone else's email. Never part of a cached page.
 */
class Identity implements SectionSourceInterface
{
    /**
     * Identity constructor.
     *
     * @param CustomerSession $customerSession
     * @param Connection $connection
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly Connection $connection,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The signed-in customer's email and its signature, or [] for guests and unconnected store views.
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getSectionData(): array
    {
        if (!$this->customerSession->isLoggedIn()) {
            return [];
        }
        $storeId = (int) $this->storeManager->getStore()->getId();
        $secret = $this->connection->getWebhookSecret($storeId);
        $email = strtolower(trim((string) $this->customerSession->getCustomer()->getEmail()));
        if ($secret === '' || $email === '' || !$this->connection->isConnected($storeId)) {
            return [];
        }
        return ['identity' => ['email' => $email, 'sig' => hash_hmac('sha256', $email, $secret)]];
    }
}
