<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use PuffinMail\LifeCycle\Model\Config;

/**
 * POST /puffinmail/cart/email (form key protected) — the standard Luma checkout only saves a guest's email on
 * the cart when the order is placed. view/frontend/web/js/capture-email.js posts it as soon as it's typed, so
 * the cart can be recovered if the guest leaves.
 */
class Email implements HttpPostActionInterface
{
    /**
     * Email constructor.
     *
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param CartRepositoryInterface $cartRepository
     * @param Config $config
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly Config $config
    ) {
    }

    /**
     * Saves a guest's typed email on their cart (when capture is on). Always answers ok.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create()->setData(['ok' => true]);
        $email = strtolower(trim((string) $this->request->getParam('email')));
        if ($this->customerSession->isLoggedIn() || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $result;
        }

        $quote = $this->checkoutSession->getQuote();
        $storeId = (int) $quote->getStoreId();
        if (!$quote->getId()
            || !$quote->getItemsCount()
            || !$this->config->isSetFlag('abandoned_cart/enabled', $storeId)
            || !$this->config->isSetFlag('abandoned_cart/capture_guest_email', $storeId)
            || strcasecmp((string) $quote->getCustomerEmail(), $email) === 0
        ) {
            return $result;
        }
        $quote->setCustomerEmail($email);
        $this->cartRepository->save($quote);
        return $result;
    }
}
