<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Connection;
use PuffinMail\LifeCycle\Model\Logger;
use PuffinMail\LifeCycle\Model\NewsletterStatus;

/**
 * POST /puffinmail/webhook — subscription changes from PuffinMail (e.g. someone unsubscribed from an email),
 * so Magento's newsletter status stays right.
 *
 * Signed as `PuffinMail-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">` with the
 * webhook secret PuffinMail handed over when this store view connected. Each store view's URL is registered
 * as its own webhook URL, so the request arrives in that store view's context. Changes are applied
 * idempotently: PuffinMail retries until it gets a 2xx.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const TOLERANCE = 300;
    private const STATUSES = [
        'contact.subscribed' => 'subscribed',
        'contact.pending' => 'pending',
        'contact.unsubscribed' => 'unsubscribed',
    ];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Connection $connection,
        private readonly NewsletterStatus $newsletterStatus,
        private readonly Logger $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $storeId = (int) $this->storeManager->getStore()->getId();
        $secret = $this->connection->getWebhookSecret($storeId);
        if ($secret === '') {
            return $result->setHttpResponseCode(503)->setData(['error' => 'Not connected']);
        }

        $body = (string) $this->request->getContent();
        $header = (string) $this->request->getHeader('PuffinMail-Signature');
        if (!self::validSignature($header, $body, $secret, time())) {
            $this->logger->warning('Webhook with an invalid signature for store view ' . $storeId);
            return $result->setHttpResponseCode(401)->setData(['error' => 'Invalid signature']);
        }

        $payload = json_decode($body, true);
        $type = (string) ($payload['type'] ?? '');
        $email = strtolower(trim((string) ($payload['data']['email'] ?? '')));
        if (isset(self::STATUSES[$type]) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $changed = $this->newsletterStatus->apply($storeId, $email, self::STATUSES[$type]);
                $this->logger->debug(sprintf('Webhook %s for %s (store view %d): %s', $type, $email, $storeId, $changed ? 'applied' : 'no change'));
            } catch (\Throwable $e) {
                $this->logger->error('Webhook ' . $type . ' failed: ' . $e->getMessage());
                return $result->setHttpResponseCode(500)->setData(['error' => 'Could not apply the change']);
            }
        }
        return $result->setData(['ok' => true]);
    }

    public static function validSignature(string $header, string $body, string $secret, int $now): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        if (abs($now - (int) $parts['t']) > self::TOLERANCE) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $parts['t'] . '.' . $body, $secret), $parts['v1']);
    }

    /** Server-to-server: the HMAC signature replaces the form key. */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
