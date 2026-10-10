<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * puffinmail/* settings. Everything is read per store view: each brand can connect to its own PuffinMail store.
 */
class Config
{
    public const VERSION = '1.1.0';

    private const PREFIX = 'puffinmail/';

    /**
     * Config constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * A puffinmail/* setting for the store view (default scope when $storeId is null).
     *
     * @param string $path path under puffinmail/
     * @param int|null $storeId
     * @return string
     */
    public function getValue(string $path, ?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether a puffinmail/* yes/no setting is on for the store view.
     *
     * @param string $path path under puffinmail/
     * @param int|null $storeId
     * @return bool
     */
    public function isSetFlag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The store view's PuffinMail API key ('' if none).
     *
     * @param int $storeId
     * @return string
     */
    public function getApiKey(int $storeId): string
    {
        if ($storeId <= 0) {
            return ''; // The admin store view never syncs.
        }
        // Already decrypted: config.xml declares the Encrypted backend model for api_key, so scopeConfig
        // returns the plain value.
        return trim($this->getValue('connection/api_key', $storeId));
    }

    /**
     * The PuffinMail API base URL, without a trailing slash.
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        $url = rtrim(trim($this->getValue('connection/api_url')), '/');
        return $url !== '' ? $url : 'https://my.puffinmail.co';
    }

    /**
     * A store view syncs once it has an API key; work is queued until the connection is confirmed.
     *
     * @param int $storeId
     * @return bool
     */
    public function hasApiKey(int $storeId): bool
    {
        return $this->getApiKey($storeId) !== '';
    }

    /**
     * Whether customers are synced for the store view.
     *
     * @param int $storeId
     * @return bool
     */
    public function syncCustomers(int $storeId): bool
    {
        return $this->hasApiKey($storeId) && $this->isSetFlag('sync/customers', $storeId);
    }

    /**
     * Whether newsletter subscriptions are synced for the store view.
     *
     * @param int $storeId
     * @return bool
     */
    public function syncNewsletter(int $storeId): bool
    {
        return $this->hasApiKey($storeId) && $this->isSetFlag('sync/newsletter', $storeId);
    }

    /**
     * Whether orders are synced for the store view.
     *
     * @param int $storeId
     * @return bool
     */
    public function syncOrders(int $storeId): bool
    {
        return $this->hasApiKey($storeId) && $this->isSetFlag('sync/orders', $storeId);
    }

    /**
     * The consent text recorded with newsletter opt-ins.
     *
     * @param int $storeId
     * @return string
     */
    public function getConsentText(int $storeId): string
    {
        return trim($this->getValue('sync/consent_text', $storeId));
    }

    /**
     * The `notify` value for an email event: only newsletter subscribers (default) or everyone.
     *
     * @param string $flow
     * @param int $storeId
     * @return string
     */
    public function getNotify(string $flow, int $storeId): string
    {
        return $this->getValue($flow . '/audience', $storeId) === 'always' ? 'always' : 'subscribed';
    }

    /**
     * Whether debug logging is on.
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->isSetFlag('advanced/debug');
    }
}
