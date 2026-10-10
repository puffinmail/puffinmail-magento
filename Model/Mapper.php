<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds Store API payloads (contacts and events) from Magento customers, orders, credit memos and quotes.
 * Product URLs and images depend on the storefront theme, so callers run this under frontend emulation for
 * the store view (OutboxProcessor, AbandonedCart and Birthday do).
 *
 * Other modules can add template variables to any event: observe `puffinmail_event_properties` and change
 * `transport->getData('properties')` (also passed: event_type, store, and quote/order/customer_id as relevant).
 */
class Mapper
{
    /** Order states whose orders count as purchases (closed = refunded; refunds are subtracted). */
    public const PAID_STATES = [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED];

    /** @var array<string, Product|null> */
    private array $products = [];

    /**
     * Mapper constructor.
     *
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerRegistry $customerRegistry
     * @param ProductRepositoryInterface $productRepository
     * @param QuoteFactory $quoteFactory
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param PriceCurrencyInterface $priceCurrency
     * @param ImageHelper $imageHelper
     * @param EventManager $eventManager
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerRegistry $customerRegistry,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly QuoteFactory $quoteFactory,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ImageHelper $imageHelper,
        private readonly EventManager $eventManager
    ) {
    }

    // -- Contacts ------------------------------------------------------------------------------------------

    /**
     * Contact from a registered customer (default billing address), or null if the customer is gone.
     *
     * @param int $customerId
     * @return array|null
     */
    public function contactFromCustomer(int $customerId): ?array
    {
        try {
            // Fresh, not the per-process registry copy (cron runs many jobs in one process).
            $this->customerRegistry->remove($customerId);
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        $storeId = $this->customerStoreId((int) $customer->getStoreId(), (int) $customer->getWebsiteId());

        $address = null;
        foreach ($customer->getAddresses() ?: [] as $candidate) {
            if ($address === null || (string) $candidate->getId() === (string) $customer->getDefaultBilling()) {
                $address = $candidate;
            }
        }
        $dob = (string) $customer->getDob();

        return $this->clean([
            'email' => $customer->getEmail(),
            'external_id' => (string) $customer->getId(),
            'first_name' => $customer->getFirstname(),
            'last_name' => $customer->getLastname(),
            'birthday' => preg_match('/^\d{4}-\d{2}-\d{2}/', $dob) ? substr($dob, 0, 10) : null,
            'language' => $this->language($storeId),
            'timezone' => $this->timezone($storeId),
            'stats' => $this->stats((string) $customer->getEmail(), $storeId),
        ] + ($address ? $this->customerAddress($address) : []));
    }

    /**
     * Contact from an order's billing details (guests, or a customer's latest details).
     *
     * @param Order $order
     * @return array
     */
    public function contactFromOrder(Order $order): array
    {
        $billing = $order->getBillingAddress();
        $storeId = (int) $order->getStoreId();
        $contact = [
            'email' => $order->getCustomerEmail(),
            'external_id' => $order->getCustomerId() ? (string) $order->getCustomerId() : null,
            'first_name' => $order->getCustomerFirstname() ?: ($billing ? $billing->getFirstname() : null),
            'last_name' => $order->getCustomerLastname() ?: ($billing ? $billing->getLastname() : null),
            'language' => $this->language($storeId),
            'timezone' => $this->timezone($storeId),
            'stats' => $this->stats((string) $order->getCustomerEmail(), $storeId),
        ];
        if ($billing) {
            $street = (array) $billing->getStreet();
            $contact += [
                'phone' => $billing->getTelephone(),
                'address_line_1' => $street[0] ?? null,
                'address_line_2' => implode(', ', array_slice($street, 1)) ?: null,
                'city' => $billing->getCity(),
                'state' => $billing->getRegion(),
                'postcode' => $billing->getPostcode(),
                'country' => $billing->getCountryId(),
            ];
        }
        return $this->clean($contact);
    }

    /**
     * Purchase totals for an address across the store view's website (registered and guest orders alike).
     *
     * @param string $email
     * @param int $storeId
     * @return array{orders_count: int, total_spent: float, first_order_at: ?string, last_order_at: ?string}
     */
    public function stats(string $email, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), [
                    'n' => 'COUNT(*)',
                    'spent' => 'SUM(grand_total - IFNULL(total_refunded, 0))',
                    'first' => 'MIN(created_at)',
                    'last' => 'MAX(created_at)',
                ])
                ->where('customer_email = ?', $email)
                ->where('state IN (?)', self::PAID_STATES)
                ->where('store_id IN (?)', $this->websiteStoreIds($storeId))
        ) ?: [];

        return [
            'orders_count' => (int) ($row['n'] ?? 0),
            'total_spent' => round((float) ($row['spent'] ?? 0), 2),
            'first_order_at' => $this->iso($row['first'] ?? null),
            'last_order_at' => $this->iso($row['last'] ?? null),
        ];
    }

    // -- Events --------------------------------------------------------------------------------------------

    /**
     * Event order.placed (with revenue), order.completed or order.cancelled.
     *
     * @param Order $order
     * @param string $type
     * @return array
     */
    public function orderEvent(Order $order, string $type): array
    {
        $event = [
            'type' => $type,
            'id' => 'order:' . $order->getId(),
            'email' => $order->getCustomerEmail(),
            'occurred_at' => $type === 'order.placed' ? $this->iso($order->getCreatedAt()) : gmdate('c'),
            'currency' => $order->getOrderCurrencyCode(),
            'properties' => $this->orderProperties($order),
        ];
        if ($type === 'order.placed') {
            $event['value'] = round((float) $order->getGrandTotal(), 2);
        }
        return $this->extend($event, $order->getStore(), ['order_id' => (int) $order->getId()]);
    }

    /**
     * Event order.refunded: a negative value, so lifetime value in PuffinMail stays right.
     *
     * @param Order $order
     * @param Creditmemo $creditmemo
     * @return array
     */
    public function refundEvent(Order $order, Creditmemo $creditmemo): array
    {
        return $this->extend([
            'type' => 'order.refunded',
            'id' => 'refund:' . $creditmemo->getId(),
            'email' => $order->getCustomerEmail(),
            'occurred_at' => $this->iso($creditmemo->getCreatedAt()) ?? gmdate('c'),
            'currency' => $order->getOrderCurrencyCode(),
            'value' => -1 * round(abs((float) $creditmemo->getGrandTotal()), 2),
            'properties' => [
                'order_id' => (int) $order->getId(),
                'order_number' => (string) $order->getIncrementId(),
                'refund_number' => (string) $creditmemo->getIncrementId(),
                'reason' => (string) $creditmemo->getCustomerNote(),
                'full_refund' => (float) $order->getTotalRefunded() >= (float) $order->getGrandTotal() - 0.005,
            ],
        ], $order->getStore(), ['order_id' => (int) $order->getId()]);
    }

    /**
     * Order template variables: number, totals, payment and shipping, and the product lines.
     *
     * @param Order $order
     * @return array
     */
    public function orderProperties(Order $order): array
    {
        $storeId = (int) $order->getStoreId();
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $children = $item->getChildrenItems();
            $items[] = $this->productLine(
                (int) $item->getProductId(),
                $children ? (int) reset($children)->getProductId() : null,
                (string) $item->getSku(),
                (string) $item->getName(),
                (float) $item->getQtyOrdered(),
                (float) $item->getPriceInclTax(),
                $storeId
            );
        }
        $payment = $order->getPayment();

        return [
            'order_id' => (int) $order->getId(),
            'order_number' => (string) $order->getIncrementId(),
            'status' => (string) $order->getStatus(),
            'total' => round((float) $order->getGrandTotal(), 2),
            'subtotal' => round((float) $order->getSubtotalInclTax(), 2),
            'discount' => round(abs((float) $order->getDiscountAmount()), 2),
            'shipping' => round((float) $order->getShippingInclTax(), 2),
            'tax' => round((float) $order->getTaxAmount(), 2),
            'total_formatted' => $this->format((float) $order->getGrandTotal(), $storeId),
            'coupons' => $order->getCouponCode() ? [(string) $order->getCouponCode()] : [],
            'payment_method' => $payment
                ? (string) ($payment->getAdditionalInformation('method_title') ?: $payment->getMethod())
                : '',
            'shipping_method' => (string) $order->getShippingDescription(),
            'customer_type' => $order->getCustomerIsGuest() ? 'guest' : 'registered',
            'order_url' => $order->getCustomerIsGuest()
                ? ''
                : $order->getStore()->getUrl('sales/order/view', ['order_id' => $order->getId(), '_nosid' => true]),
            'items' => $items,
            'item_count' => array_sum(array_column($items, 'quantity')),
        ];
    }

    /**
     * Cart contents for abandoned-cart events: items, total and item count.
     *
     * @param int $quoteId
     * @param int $storeId
     * @return array{items: array, total: float, total_formatted: string, item_count: float}
     */
    public function cartProperties(int $quoteId, int $storeId): array
    {
        $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
        $items = [];
        foreach ($quote->getId() ? $quote->getAllVisibleItems() : [] as $item) {
            $children = $item->getChildren();
            $qty = (float) $item->getQty();
            $rowTotal = $item->getRowTotalInclTax() !== null
                ? (float) $item->getRowTotalInclTax()
                : (float) $item->getRowTotal();
            $items[] = $this->productLine(
                (int) $item->getProductId(),
                $children ? (int) reset($children)->getProductId() : null,
                (string) $item->getSku(),
                (string) $item->getName(),
                $qty,
                $qty > 0 ? $rowTotal / $qty : 0.0,
                $storeId
            );
        }
        // The quote has no subtotal incl. tax column; add up the lines (unit price incl. tax × qty).
        $total = 0.0;
        foreach ($items as $line) {
            $total += $line['price'] * $line['quantity'];
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
            'total_formatted' => $this->format($total, $storeId),
            'item_count' => array_sum(array_column($items, 'quantity')),
        ];
    }

    /**
     * Lets other modules add template variables (event `puffinmail_event_properties`), then returns the event.
     *
     * @param array $event
     * @param StoreInterface $store
     * @param array $context
     * @return array
     */
    public function extend(array $event, StoreInterface $store, array $context = []): array
    {
        $transport = new DataObject(['properties' => $event['properties'] ?? []]);
        $this->eventManager->dispatch('puffinmail_event_properties', [
            'transport' => $transport,
            'event_type' => $event['type'],
            'store' => $store,
        ] + $context);
        $event['properties'] = (array) $transport->getData('properties');
        return $event;
    }

    /**
     * Store-level template variables every email event carries.
     *
     * @param StoreInterface $store
     * @return array
     */
    public function storeProperties(StoreInterface $store): array
    {
        return [
            'store_name' => (string) $store->getFrontendName(),
            'shop_url' => (string) $store->getBaseUrl(),
        ];
    }

    /**
     * A product line for orders and carts: enough for an email to show the product.
     *
     * `price` is the unit price including tax; `price_formatted` the same in the store's currency format.
     *
     * @param int $productId
     * @param int|null $variantId
     * @param string $sku
     * @param string $name
     * @param float $quantity
     * @param float $price
     * @param int $storeId
     * @return array
     */
    public function productLine(
        int $productId,
        ?int $variantId,
        string $sku,
        string $name,
        float $quantity,
        float $price,
        int $storeId
    ): array {
        $line = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'sku' => $sku,
            'name' => $name,
            'quantity' => $quantity == (int) $quantity ? (int) $quantity : $quantity,
            'price' => round($price, 2),
            'price_formatted' => $this->format($price, $storeId),
            'url' => '',
            'image_url' => '',
        ];
        $product = $this->product($productId, $storeId);
        if ($product) {
            if ((int) $product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE) {
                $line['url'] = (string) $product->getProductUrl();
            }
            try {
                $line['image_url'] = (string) $this->imageHelper
                    ->init($product, 'cart_page_product_thumbnail')
                    ->getUrl();
            } catch (\Throwable $e) {
                $line['image_url'] = '';
            }
        }
        return $line;
    }

    /**
     * An amount in the store view's currency format.
     *
     * @param float $amount
     * @param int $storeId
     * @return string
     */
    public function format(float $amount, int $storeId): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $storeId
        );
    }

    // -- Catalogue -----------------------------------------------------------------------------------------

    /**
     * A catalogue product (Store API POST /products) as the store view shows it.
     *
     * Current price (special/catalogue rule price included), the regular price when on sale, the product image
     * and stock. Products that aren't enabled, visible in the catalogue and in the store view's website are sent
     * as deleted, so emails leave them out.
     *
     * @param Product $product
     * @param int $storeId
     * @return array
     * @throws NoSuchEntityException
     */
    public function catalogProduct(Product $product, int $storeId): array
    {
        $store = $this->storeManager->getStore($storeId);
        $priceInfo = $product->getPriceInfo();
        $price = (float) $priceInfo->getPrice('final_price')->getAmount()->getValue();
        $regular = (float) $priceInfo->getPrice('regular_price')->getAmount()->getValue();
        $visible = (int) $product->getStatus()
                === \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
            && in_array(
                (int) $product->getVisibility(),
                [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
                true
            )
            && in_array((int) $store->getWebsiteId(), array_map('intval', (array) $product->getWebsiteIds()), true);
        $url = $visible ? (string) $product->getProductUrl() : '';
        try {
            $image = (string) $this->imageHelper->init($product, 'category_page_grid')->getUrl();
        } catch (\Throwable $e) {
            $image = '';
        }
        $categories = [];
        try {
            foreach ($product->getCategoryCollection()->addAttributeToSelect('name')->setPageSize(20) as $category) {
                $categories[] = (string) $category->getName();
            }
        } catch (\Throwable $e) {
            $categories = [];
        }
        return [
            'id' => (string) $product->getId(),
            'name' => (string) $product->getName() ?: ('#' . $product->getId()),
            // The API needs a URL even for a withdrawn product: fall back to the shop's home page.
            'url' => preg_match('#^https?://#', $url) ? $url : (string) $store->getBaseUrl(),
            'image_url' => preg_match('#^https?://#', $image) && !str_contains($image, '/placeholder/') ? $image : null,
            'price' => round($price, 2),
            'regular_price' => $regular > $price ? round($regular, 2) : null,
            'currency' => (string) $store->getBaseCurrencyCode(),
            'sku' => (string) $product->getSku(),
            'categories' => array_values(array_filter($categories)),
            'in_stock' => (bool) $product->isSalable(),
            'deleted' => !$visible,
        ];
    }

    /**
     * A withdrawn product (deleted in Magento): enough for the API to drop it from emails.
     *
     * @param Product $product
     * @param int $storeId
     * @return array
     * @throws NoSuchEntityException
     */
    public function deletedProduct(Product $product, int $storeId): array
    {
        $store = $this->storeManager->getStore($storeId);
        return [
            'id' => (string) $product->getId(),
            'name' => (string) $product->getName() ?: ('#' . $product->getId()),
            'url' => (string) $store->getBaseUrl(),
            'price' => max(0.0, round((float) $product->getPrice(), 2)),
            'deleted' => true,
        ];
    }

    /**
     * Converts "Y-m-d H:i:s" (UTC, as Magento stores it) to ISO 8601.
     *
     * @param string|null $utc
     * @return string|null
     */
    public function iso(?string $utc): ?string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return null;
        }
        $ts = strtotime($utc . ' UTC');
        return $ts ? gmdate('c', $ts) : null;
    }

    /**
     * The store view a customer belongs to.
     *
     * Where they registered, or their website's default store view for admin-created customers (store_id 0).
     *
     * @param int $storeId
     * @param int $websiteId
     * @return int
     */
    public function customerStoreId(int $storeId, int $websiteId): int
    {
        if ($storeId > 0) {
            return $storeId;
        }
        try {
            return (int) $this->storeManager->getWebsite($websiteId)->getDefaultStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Store views of the store view's website (newsletter and order history are per website).
     *
     * @param int $storeId
     * @return int[]
     */
    public function websiteStoreIds(int $storeId): array
    {
        try {
            $ids = $this->storeManager->getStore($storeId)->getWebsite()->getStoreIds();
        } catch (\Throwable $e) {
            return [$storeId];
        }
        return array_map('intval', array_values($ids)) ?: [$storeId];
    }

    /**
     * Contact address fields from a customer address.
     *
     * @param AddressInterface $address
     * @return array
     */
    private function customerAddress(AddressInterface $address): array
    {
        $street = (array) $address->getStreet();
        return [
            'phone' => $address->getTelephone(),
            'address_line_1' => $street[0] ?? null,
            'address_line_2' => implode(', ', array_slice($street, 1)) ?: null,
            'city' => $address->getCity(),
            'state' => $address->getRegion() ? $address->getRegion()->getRegion() : null,
            'postcode' => $address->getPostcode(),
            'country' => $address->getCountryId(),
        ];
    }

    /**
     * A product in a store view, cached per process (null if it no longer exists).
     *
     * @param int $productId
     * @param int $storeId
     * @return Product|null
     */
    private function product(int $productId, int $storeId): ?Product
    {
        $key = $storeId . ':' . $productId;
        if (!array_key_exists($key, $this->products)) {
            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
                $this->products[$key] = $product instanceof Product ? $product : null;
            } catch (NoSuchEntityException $e) {
                $this->products[$key] = null;
            }
            if (count($this->products) > 500) {
                $this->products = array_slice($this->products, -250, null, true);
            }
        }
        return $this->products[$key];
    }

    /**
     * The store view's language from its locale ("en_AU" -> "en").
     *
     * @param int $storeId
     * @return string|null
     */
    private function language(int $storeId): ?string
    {
        $locale = (string) $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
        return $locale !== '' ? strtolower(substr($locale, 0, 2)) : null;
    }

    /**
     * The store view's timezone, or null if none is set.
     *
     * @param int $storeId
     * @return string|null
     */
    private function timezone(int $storeId): ?string
    {
        return (string) $this->scopeConfig->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: null;
    }

    /**
     * Drops empty fields (the API leaves omitted fields unchanged) and trims to the API's length limits.
     *
     * @param array $contact
     * @return array
     */
    private function clean(array $contact): array
    {
        $limits = ['phone' => 50, 'state' => 100, 'postcode' => 20, 'country' => 2];
        foreach ($contact as $field => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $value = mb_substr($value, 0, $limits[$field] ?? 255);
            }
            if ($value === null || $value === '') {
                unset($contact[$field]);
            } else {
                $contact[$field] = $value;
            }
        }
        return $contact;
    }
}
