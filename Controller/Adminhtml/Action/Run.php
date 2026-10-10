<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Controller\Adminhtml\Action;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Backfill;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Connection;
use PuffinMail\LifeCycle\Model\Outbox;

/**
 * Buttons of the status panel (Stores > Configuration > PuffinMail): ?do=reconnect|retry|backfill|backfill_cancel.
 * Admin URLs carry the secret key, so these are safe as GET links.
 */
class Run extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PuffinMail_LifeCycle::config';

    /**
     * Run constructor.
     *
     * @param Context $context
     * @param Config $config
     * @param Connection $connection
     * @param Outbox $outbox
     * @param Backfill $backfill
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly Backfill $backfill,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * Runs the requested action, then goes back to the PuffinMail configuration page.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        switch ((string) $this->getRequest()->getParam('do')) {
            case 'reconnect':
                foreach ($this->storeManager->getStores() as $store) {
                    if (!$this->config->hasApiKey((int) $store->getId())) {
                        continue;
                    }
                    try {
                        $data = $this->connection->connect((int) $store->getId());
                        $this->messageManager->addSuccessMessage(
                            __('%1 is connected to PuffinMail store "%2".', $store->getName(), $data['store_name'])
                        );
                    } catch (\Throwable $e) {
                        $this->messageManager->addErrorMessage(
                            __('%1 could not connect to PuffinMail: %2', $store->getName(), $e->getMessage())
                        );
                    }
                }
                break;
            case 'retry':
                $this->messageManager->addSuccessMessage(
                    __('%1 failed item(s) will be retried within a minute.', $this->outbox->retryFailed())
                );
                break;
            case 'backfill':
                $months = max(0, (int) $this->getRequest()->getParam('months'));
                $this->backfill->start($months);
                $this->messageManager->addSuccessMessage(
                    __('Historical sync started. It runs in the background (cron); this page shows its progress.')
                );
                break;
            case 'backfill_cancel':
                $this->backfill->cancel();
                $this->messageManager->addSuccessMessage(__('Historical sync stopped.'));
                break;
        }
        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'puffinmail']);
    }
}
