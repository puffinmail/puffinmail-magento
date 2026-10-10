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

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getValue(string $path, ?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isSetFlag(string $path, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiKey(int $storeId): string
    {
        if ($storeId <= 0) {
            return ''; // The admin store view never syncs.
        }
        // Already decrypted: config.xml declares the Encrypted backend model for api_key, so scopeConfig
        // returns the plain value.
        return trim($this->getValue('connection/api_key', $storeId));
    }

    public function getApiUrl(): string
    {
        $url = rtrim(trim($this->getValue('connection/api_url')), '/');
        return $url !== '' ? $url : 'https://my.puffinmail.co';
    }

    /** A store view syncs once it has an API key; work is queued until the connection is confirmed. */
    public function hasApiKey(int $storeId): bool
    {
        return $this->getApiKey($storeId) !== '';
    }

    public function syncCustomers(int $storeId): bool
    {
        return $this->hasApiKey($storeId) && $this->isSetFlag('sync/customers', $storeId);
    }

    public function syncNewsletter(int $storeId): bool
    {
        return $this->hasApiKey($storeId) && $this->isSetFlag('sync/newsletter', $storeId);
    }

    public function syncOrders(int $storeId): bool
    {
        return $this->hasApiKey($storeId) && $this->isSetFlag('sync/orders', $storeId);
    }

    public function getConsentText(int $storeId): string
    {
        return trim($this->getValue('sync/consent_text', $storeId));
    }

    /** `notify` for an email event: only newsletter subscribers (default) or everyone. */
    public function getNotify(string $flow, int $storeId): string
    {
        return $this->getValue($flow . '/audience', $storeId) === 'always' ? 'always' : 'subscribed';
    }

    public function isDebug(): bool
    {
        return $this->isSetFlag('advanced/debug');
    }
}
