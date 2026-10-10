<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model\Api;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\App\ProductMetadataInterface;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Logger;

/**
 * Client for the PuffinMail Store API v1 (https://my.puffinmail.co/api/v1). Authenticates with the store
 * view's API key from the PuffinMail portal. The module never talks to the email engine directly, so the
 * engine can change without breaking installed shops.
 */
class Client
{
    public const CONTACT_BATCH = 100;
    public const EVENT_BATCH = 50;
    public const PRODUCT_BATCH = 100;

    /**
     * Client constructor.
     *
     * @param Config $config
     * @param ClientFactory $clientFactory
     * @param ProductMetadataInterface $productMetadata
     * @param Logger $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ClientFactory $clientFactory,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly Logger $logger
    ) {
    }

    /**
     * Reads the PuffinMail store the store view's API key belongs to.
     *
     * @param int $storeId
     * @return array{store: array, organization: array, newsletter: array, webhook_secret: string}
     * @throws ApiException
     */
    public function getStore(int $storeId): array
    {
        return $this->request($storeId, 'GET', 'api/v1/store');
    }

    /**
     * Tells PuffinMail about the shop (URL, webhook URL, versions). Returns the same as getStore().
     *
     * @param int $storeId
     * @param array $details
     * @return array
     * @throws ApiException
     */
    public function updateStore(int $storeId, array $details): array
    {
        return $this->request($storeId, 'PATCH', 'api/v1/store', $details);
    }

    /**
     * Upserts up to 100 contacts, keyed by email.
     *
     * @param int $storeId
     * @param array $contacts
     * @return array{results: array<int, array{email: string, ok: bool, error?: string}>}
     * @throws ApiException
     */
    public function upsertContacts(int $storeId, array $contacts): array
    {
        return $this->request($storeId, 'POST', 'api/v1/contacts', ['contacts' => array_values($contacts)]);
    }

    /**
     * Unsubscribes the contact from the store's newsletter in PuffinMail.
     *
     * @param int $storeId
     * @param string $email
     * @return void
     * @throws ApiException
     */
    public function unsubscribe(int $storeId, string $email): void
    {
        $this->request($storeId, 'POST', 'api/v1/contacts/unsubscribe', ['email' => $email]);
    }

    /**
     * Erases the contact in PuffinMail (privacy erasure requests).
     *
     * @param int $storeId
     * @param string $email
     * @return void
     * @throws ApiException
     */
    public function deleteContact(int $storeId, string $email): void
    {
        $this->request($storeId, 'POST', 'api/v1/contacts/delete', ['email' => $email]);
    }

    /**
     * Records up to 50 events.
     *
     * Re-sending the same type and id updates the event (and re-triggers automations).
     *
     * @param int $storeId
     * @param array $events
     * @return array{results: array<int, array{id: string, type: string, ok: bool, error?: string, email?: string}>}
     * @throws ApiException
     */
    public function sendEvents(int $storeId, array $events): array
    {
        return $this->request($storeId, 'POST', 'api/v1/events', ['events' => array_values($events)]);
    }

    /**
     * Upserts up to 100 catalogue products (POST /api/v1/products), keyed by product id.
     *
     * @param int $storeId
     * @param array $products
     * @return array{results: array<int, array{id: string, ok: bool, error?: string}>}
     * @throws ApiException
     */
    public function upsertProducts(int $storeId, array $products): array
    {
        return $this->request($storeId, 'POST', 'api/v1/products', ['products' => array_values($products)]);
    }

    /**
     * Makes a Store API call with the store view's API key and returns the decoded JSON body.
     *
     * @param int $storeId
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return array
     * @throws ApiException
     */
    private function request(int $storeId, string $method, string $path, ?array $body = null): array
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            throw new ApiException('No PuffinMail API key is set for store view ' . $storeId, 401, 'not_connected');
        }

        $client = $this->clientFactory->create(['config' => [
            'base_uri' => $this->config->getApiUrl() . '/',
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'User-Agent' => sprintf(
                    'PuffinMail-Magento/%s Magento/%s',
                    Config::VERSION,
                    $this->productMetadata->getVersion()
                ),
            ],
        ]]);

        $options = $body !== null ? ['json' => $body] : [];
        try {
            $response = $client->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new ApiException('PuffinMail is unreachable: ' . $e->getMessage(), 0, 'http_error', 0, $e);
        } catch (\InvalidArgumentException $e) {
            // e.g. a malformed key that can't be sent as a header: treat like a rejected key.
            throw new ApiException('Invalid request to PuffinMail: ' . $e->getMessage(), 401, 'unauthorized', 0, $e);
        }

        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];
        $this->logger->debug(sprintf('%s /%s (store %d) -> HTTP %d', $method, $path, $storeId, $status));

        if ($status >= 200 && $status < 300) {
            return $data;
        }
        throw new ApiException(
            sprintf('PuffinMail %s /%s failed (HTTP %d): %s', $method, $path, $status, $data['error'] ?? 'no message'),
            $status,
            (string) ($data['code'] ?? 'api_error'),
            (int) $response->getHeaderLine('Retry-After')
        );
    }
}
