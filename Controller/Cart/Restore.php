<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\QuoteFactory;
use PuffinMail\LifeCycle\Model\AbandonedCart;
use PuffinMail\LifeCycle\Model\Logger;

/**
 * GET /puffinmail/cart/restore?t=<token> — the `restore_url` in abandoned-cart emails. Guest carts are put
 * back into the session; customer carts need the customer signed in (Magento then loads their active cart).
 * The email's coupon, if any, is applied. The click is recorded for the `cart.recovered` event.
 */
class Restore implements HttpGetActionInterface
{
    /**
     * Restore constructor.
     *
     * @param RequestInterface $request
     * @param RedirectFactory $redirectFactory
     * @param QuoteFactory $quoteFactory
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param ManagerInterface $messageManager
     * @param UrlInterface $url
     * @param AbandonedCart $abandonedCart
     * @param Logger $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly QuoteFactory $quoteFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly ManagerInterface $messageManager,
        private readonly UrlInterface $url,
        private readonly AbandonedCart $abandonedCart,
        private readonly Logger $logger
    ) {
    }

    /**
     * Restores the cart for the token and redirects to the cart page (or sign-in for a customer's cart).
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create()->setPath('checkout/cart');
        $cart = $this->abandonedCart->getByToken((string) $this->request->getParam('t'));
        if (!$cart) {
            return $redirect;
        }
        $quoteId = (int) $cart['quote_id'];
        $this->abandonedCart->markClicked($quoteId);

        $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
        if (!$quote->getId() || !$quote->getIsActive() || $cart['status'] === 'recovered') {
            $this->messageManager->addNoticeMessage(__('That cart is no longer available.'));
            return $redirect;
        }

        $customerId = (int) $quote->getCustomerId();
        if ($customerId > 0) {
            if ((int) $this->customerSession->getCustomerId() !== $customerId) {
                $this->customerSession->setBeforeAuthUrl($this->url->getUrl('checkout/cart'));
                $this->messageManager->addNoticeMessage(__('Please sign in to see your saved cart.'));
                return $redirect->setPath('customer/account/login');
            }
        } elseif ($this->customerSession->isLoggedIn()) {
            return $redirect; // Don't swap a signed-in customer's own cart for a guest one.
        } else {
            $this->checkoutSession->replaceQuote($quote);
        }

        $coupon = (string) ($cart['coupon_code'] ?? '');
        if ($coupon !== '' && !$quote->getCouponCode()) {
            try {
                $quote->setCouponCode($coupon)->collectTotals()->save();
            } catch (\Throwable $e) {
                $this->logger->warning('Could not apply coupon on cart restore: ' . $e->getMessage());
            }
        }
        return $redirect;
    }
}
