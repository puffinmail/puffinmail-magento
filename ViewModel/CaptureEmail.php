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
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly UrlInterface $url
    ) {
    }

    public function isEnabled(): bool
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        return !$this->customerSession->isLoggedIn()
            && $this->config->hasApiKey($storeId)
            && $this->config->isSetFlag('abandoned_cart/enabled', $storeId)
            && $this->config->isSetFlag('abandoned_cart/capture_guest_email', $storeId);
    }

    public function getUrl(): string
    {
        return $this->url->getUrl('puffinmail/cart/email', ['_secure' => true]);
    }
}
