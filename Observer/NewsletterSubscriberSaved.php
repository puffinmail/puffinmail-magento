<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Newsletter\Model\Subscriber;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Logger;
use PuffinMail\LifeCycle\Model\Outbox;

/**
 * A Magento newsletter subscribe queues a contact with a consent record (subscribing them to the PuffinMail
 * store's newsletter list); an unsubscribe queues an unsubscribe. Only actual status changes are sent.
 * "Not activated" (Magento's own double opt-in) waits until the subscriber confirms.
 */
class NewsletterSubscriberSaved implements ObserverInterface
{
    /** Where the subscription came from, by full action name. */
    private const SOURCES = [
        'newsletter_subscriber_new' => 'signup_form',
        'newsletter_manage_save' => 'account',
        'customer_account_createpost' => 'registration',
        'customer_account_editPost' => 'account',
        'checkout_onepage_success' => 'checkout',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Outbox $outbox,
        private readonly RequestInterface $request,
        private readonly RemoteAddress $remoteAddress,
        private readonly State $appState,
        private readonly Logger $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $subscriber = $observer->getEvent()->getData('data_object');
        if (!$subscriber instanceof Subscriber || !$subscriber->dataHasChangedFor('subscriber_status')) {
            return;
        }
        $storeId = (int) $subscriber->getStoreId();
        if (!$this->config->syncNewsletter($storeId)) {
            return;
        }

        try {
            $email = (string) $subscriber->getSubscriberEmail();
            switch ((int) $subscriber->getSubscriberStatus()) {
                case Subscriber::STATUS_SUBSCRIBED:
                    $contact = ['email' => $email, 'subscription' => $this->consent($storeId)];
                    if ($subscriber->getCustomerId()) {
                        $contact['external_id'] = (string) $subscriber->getCustomerId();
                        if ($this->config->syncCustomers($storeId)) {
                            $this->outbox->ref($storeId, Outbox::KIND_CUSTOMER, (int) $subscriber->getCustomerId());
                        }
                    }
                    $this->outbox->contact($storeId, $contact);
                    break;
                case Subscriber::STATUS_UNSUBSCRIBED:
                    $this->outbox->unsubscribe($storeId, $email);
                    break;
            }
        } catch (DeadlockException | LockWaitException $e) {
            throw $e; // MySQL rolled back the transaction; Magento must see that.
        } catch (\Throwable $e) {
            // Never break a newsletter save because of the sync.
            $this->logger->error('Queuing newsletter change failed: ' . $e->getMessage());
        }
    }

    /** The consent record PuffinMail keeps with the subscription. */
    private function consent(int $storeId): array
    {
        $ip = $this->remoteAddress->getRemoteAddress();
        return array_filter([
            'source' => $this->source(),
            'consent_at' => gmdate('c'),
            'ip' => is_string($ip) && $ip !== '' ? substr($ip, 0, 64) : null,
            'text' => $this->config->getConsentText($storeId) ?: null,
        ], static fn ($value) => $value !== null);
    }

    private function source(): string
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (\Throwable $e) {
            return 'magento';
        }
        if ($area === Area::AREA_ADMINHTML) {
            return 'admin';
        }
        if ($this->request instanceof HttpRequest) {
            $action = (string) $this->request->getFullActionName();
            if (isset(self::SOURCES[$action])) {
                return self::SOURCES[$action];
            }
            if (str_starts_with($action, 'checkout_')) {
                return 'checkout';
            }
        }
        return in_array($area, [Area::AREA_GRAPHQL, Area::AREA_WEBAPI_REST], true) ? 'api' : 'magento';
    }
}
