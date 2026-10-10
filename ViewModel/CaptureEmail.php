<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Config;

class CaptureEmail implements ArgumentInterface
{
    /**
     * CaptureEmail constructor.
     *
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param CustomerSession $customerSession
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * Whether to capture a guest's checkout email: a guest, a connected store view and the setting on.
     *
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function isEnabled(): bool
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        return !$this->customerSession->isLoggedIn()
            && $this->config->hasApiKey($storeId)
            && $this->config->isSetFlag('abandoned_cart/enabled', $storeId)
            && $this->config->isSetFlag('abandoned_cart/capture_guest_email', $storeId);
    }

    /**
     * The URL capture-email.js posts the email to (Controller\Cart\Email).
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url->getUrl('puffinmail/cart/email', ['_secure' => true]);
    }
}
