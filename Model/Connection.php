<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Api\ApiException;
use PuffinMail\LifeCycle\Model\Api\Client;

/**
 * The link between a store view and its PuffinMail store. Connecting reads the store details (and the secret
 * PuffinMail signs webhooks with) and tells PuffinMail the shop's URL and webhook URL.
 *
 * Details are kept per API key (several store views can share one PuffinMail store), in the flag table.
 * A 401 from any call marks the connection as rejected: syncing pauses (the outbox keeps everything) until the
 * key is fixed and the connection refreshed.
 */
class Connection
{
    private const FLAG_PREFIX = 'puffinmail_connection_';
    /** Automatic reconnect attempts (from cron) for a store view that isn't connected yet. */
    private const RETRY_SECONDS = 900;

    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly FlagManager $flagManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array{store_id?: string, store_name?: string, organization?: string, list_name?: string,
     *   webhook_secret?: string, site_key?: string, script_url?: string, connected_at?: int, auth_error?: string,
     *   last_error?: string, attempted_at?: int, storefront_checked_at?: int}
     */
    public function get(int $storeId): array
    {
        $key = $this->flagKey($storeId);
        if ($key === null) {
            return [];
        }
        $data = $this->flagManager->getFlagData($key);
        return is_array($data) ? $data : [];
    }

    public function isConnected(int $storeId): bool
    {
        $data = $this->get($storeId);
        return !empty($data['store_id']) && empty($data['auth_error']);
    }

    public function getWebhookSecret(int $storeId): string
    {
        return (string) ($this->get($storeId)['webhook_secret'] ?? '');
    }

    /**
     * Connects (or refreshes) a store view.
     *
     * @throws ApiException
     */
    public function connect(int $storeId): array
    {
        $key = $this->flagKey($storeId);
        if ($key === null) {
            throw new ApiException('No PuffinMail API key is set for store view ' . $storeId, 401, 'not_connected');
        }
        $store = $this->storeManager->getStore($storeId);

        try {
            $this->client->getStore($storeId);
            $response = $this->client->updateStore($storeId, $this->shopDetails($store));
        } catch (ApiException $e) {
            $data = $this->get($storeId);
            $data['attempted_at'] = time();
            $data['last_error'] = $e->getMessage();
            if ($e->isUnauthorized()) {
                $data['auth_error'] = $e->getMessage();
            }
            $this->flagManager->saveFlag($key, $data);
            throw $e;
        }

        $data = [
            'store_id' => (string) ($response['store']['id'] ?? ''),
            'store_name' => (string) ($response['store']['name'] ?? ''),
            'organization' => (string) ($response['organization']['name'] ?? ''),
            'list_name' => (string) ($response['newsletter']['list_name'] ?? ''),
            'webhook_secret' => (string) ($response['webhook_secret'] ?? ''),
            // The storefront script (website visits, browse abandonment, signup forms).
            'site_key' => (string) ($response['storefront']['site_key'] ?? ''),
            'script_url' => (string) ($response['storefront']['script_url'] ?? ''),
            'connected_at' => time(),
        ];
        $this->flagManager->saveFlag($key, $data);
        $this->logger->info(sprintf('Store view %s connected to PuffinMail store "%s"', $store->getCode(), $data['store_name']));
        return $data;
    }

    /**
     * Used by cron: connects store views that have a key but no (working) connection, at most every 15 minutes.
     */
    public function ensureConnected(int $storeId): bool
    {
        if ($this->isConnected($storeId)) {
            return true;
        }
        if (!$this->config->hasApiKey($storeId)) {
            return false;
        }
        $data = $this->get($storeId);
        if (!empty($data['auth_error']) || time() - (int) ($data['attempted_at'] ?? 0) < self::RETRY_SECONDS) {
            return false; // A rejected key needs a fix in the admin; other errors retry later.
        }
        try {
            $this->connect($storeId);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('PuffinMail connect failed for store view ' . $storeId . ': ' . $e->getMessage());
            return false;
        }
    }

    /** The storefront script URL for the store view ('' if not connected). */
    public function getScriptUrl(int $storeId): string
    {
        return $this->isConnected($storeId) ? (string) ($this->get($storeId)['script_url'] ?? '') : '';
    }

    /**
     * Connections made before the storefront script existed don't know its URL: refresh them (from cron), at most
     * once a day.
     */
    public function ensureStorefront(int $storeId): void
    {
        $data = $this->get($storeId);
        if (!$this->isConnected($storeId) || !empty($data['script_url']) || time() - (int) ($data['storefront_checked_at'] ?? 0) < 86400) {
            return;
        }
        $data['storefront_checked_at'] = time();
        $key = $this->flagKey($storeId);
        if ($key !== null) {
            $this->flagManager->saveFlag($key, $data);
        }
        try {
            $this->connect($storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('Refreshing the PuffinMail connection of store view ' . $storeId . ' failed: ' . $e->getMessage());
        }
    }

    /** Records a 401 seen while syncing, pausing the store view until the connection is refreshed. */
    public function markUnauthorized(int $storeId, string $error): void
    {
        $key = $this->flagKey($storeId);
        if ($key === null) {
            return;
        }
        $data = $this->get($storeId);
        $data['auth_error'] = $error;
        $data['attempted_at'] = time();
        $this->flagManager->saveFlag($key, $data);
        $this->logger->error('PuffinMail rejected the API key of store view ' . $storeId . ': ' . $error);
    }

    /**
     * Store views whose connection is confirmed and not rejected.
     *
     * @return StoreInterface[]
     */
    public function connectedStores(): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->isConnected((int) $store->getId())) {
                $stores[] = $store;
            }
        }
        return $stores;
    }

    private function shopDetails(StoreInterface $store): array
    {
        $storeId = (int) $store->getId();
        return [
            'url' => $store->getBaseUrl(),
            'webhook_url' => $store->getUrl('puffinmail/webhook', ['_secure' => true, '_nosid' => true]),
            'platform' => 'magento',
            'platform_version' => $this->productMetadata->getVersion(),
            'plugin_version' => Config::VERSION,
            'currency' => (string) $this->scopeConfig->getValue(
                Currency::XML_PATH_CURRENCY_DEFAULT,
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'timezone' => (string) $this->scopeConfig->getValue(
                'general/locale/timezone',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
        ];
    }

    /** Flag code for the store view's API key (and API URL), or null without a key. */
    private function flagKey(int $storeId): ?string
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            return null;
        }
        return self::FLAG_PREFIX . substr(hash('sha256', $this->config->getApiUrl() . '|' . $apiKey), 0, 24);
    }
}
