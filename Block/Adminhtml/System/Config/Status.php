<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Api\Data\StoreInterface;
use PuffinMail\LifeCycle\Model\Backfill;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Connection;
use PuffinMail\LifeCycle\Model\Outbox;

/**
 * Connection, queue and historical-sync status under the API key, with buttons to refresh the connection,
 * retry failed items and sync existing customers and orders.
 */
class Status extends Field
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly Backfill $backfill,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = '<div class="puffinmail-status">';
        foreach ($this->storesInScope() as $store) {
            $html .= $this->storeStatus($store);
        }

        $stats = $this->outbox->stats();
        $html .= '<p>' . $this->escapeHtml(__(
            'Waiting to sync: %1 · Failed: %2',
            $stats[Outbox::STATUS_PENDING],
            $stats[Outbox::STATUS_FAILED]
        )) . '</p>';
        if ($stats[Outbox::STATUS_FAILED] > 0) {
            $html .= '<details style="margin:4px 0 8px"><summary>' . $this->escapeHtml(__('Recent failures')) . '</summary><ul style="margin-left:16px;list-style:disc">';
            foreach ($this->outbox->recentFailures(10) as $failure) {
                $html .= '<li><code>' . $this->escapeHtml($failure['dedupe_key']) . '</code>: '
                    . $this->escapeHtml((string) $failure['last_error']) . '</li>';
            }
            $html .= '</ul></details>';
        }

        $html .= '<p style="margin-top:12px"><strong>' . $this->escapeHtml(__('Existing customers and orders')) . '</strong><br/>'
            . $this->escapeHtml($this->backfillText()) . '</p><p>';
        if ($this->backfill->state()['status'] === 'running') {
            $html .= $this->button(__('Stop'), 'backfill_cancel');
        } else {
            $html .= $this->button(__('Sync last 12 months'), 'backfill', ['months' => 12])
                . $this->button(__('Sync last 3 years'), 'backfill', ['months' => 36])
                . $this->button(__('Sync everything'), 'backfill', ['months' => 0]);
        }
        $html .= '</p><p style="margin-top:12px">';
        if ($stats[Outbox::STATUS_FAILED] > 0) {
            $html .= $this->button(__('Retry failed'), 'retry');
        }
        $html .= $this->button(__('Refresh connection'), 'reconnect') . '</p></div>';
        return $html;
    }

    private function storeStatus(StoreInterface $store): string
    {
        $storeId = (int) $store->getId();
        $name = '<strong>' . $this->escapeHtml($store->getWebsite()->getName() . ' / ' . $store->getName()) . '</strong>: ';
        if (!$this->config->hasApiKey($storeId)) {
            return '<p>' . $name . $this->escapeHtml(__('not connected (no API key)')) . '</p>';
        }
        $data = $this->connection->get($storeId);
        if (!empty($data['auth_error'])) {
            return '<p style="color:#e22626">' . $name . $this->escapeHtml(__(
                'PuffinMail rejected the API key; syncing is paused. Fix the key and save, or Refresh connection. (%1)',
                $data['auth_error']
            )) . '</p>';
        }
        if (empty($data['store_id'])) {
            return '<p style="color:#e22626">' . $name . $this->escapeHtml(__(
                'not connected yet. %1',
                $data['last_error'] ?? __('Save the configuration or click Refresh connection.')
            )) . '</p>';
        }
        $text = __('connected as "%1" (%2)', $data['store_name'] ?? '', $data['organization'] ?? '');
        if (!empty($data['list_name'])) {
            $text .= ' · ' . __('newsletter list "%1"', $data['list_name']);
        }
        $pending = $this->outbox->stats($storeId)[Outbox::STATUS_PENDING];
        $text .= ' · ' . __('%1 waiting', $pending);
        return '<p>' . $name . $this->escapeHtml($text) . '</p>';
    }

    /** @return StoreInterface[] store views of the configuration scope being edited */
    private function storesInScope(): array
    {
        $storeParam = $this->getRequest()->getParam('store');
        $websiteParam = $this->getRequest()->getParam('website');
        $stores = [];
        foreach ($this->_storeManager->getStores() as $store) {
            if (($storeParam !== null && $storeParam !== '' && (int) $storeParam !== (int) $store->getId())
                || ($websiteParam !== null && $websiteParam !== '' && (int) $websiteParam !== (int) $store->getWebsiteId())
            ) {
                continue;
            }
            $stores[] = $store;
        }
        return $stores;
    }

    private function backfillText(): string
    {
        $state = $this->backfill->state();
        return match ($state['status']) {
            'running' => (string) __('Queuing… %1 customers and %2 orders so far.', $state['customers'], $state['orders']),
            'done' => (string) __(
                'Queued %1 customers and %2 orders on %3.',
                $state['customers'],
                $state['orders'],
                $this->formatDate(new \DateTime('@' . (int) $state['finished_at']), \IntlDateFormatter::MEDIUM, true)
            ),
            default => (string) __('Send your existing customers and order history so segments and lifetime value are complete from day one. This doesn\'t subscribe anyone or send emails.'),
        };
    }

    private function button($label, string $action, array $params = []): string
    {
        $url = $this->getUrl('puffinmail/action/run', ['do' => $action] + $params);
        return sprintf(
            '<a class="action-default" style="display:inline-block;margin:0 6px 6px 0;text-decoration:none" href="%s">%s</a>',
            $this->escapeUrl($url),
            $this->escapeHtml($label)
        );
    }
}
