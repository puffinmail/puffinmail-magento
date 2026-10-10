<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\ViewModel;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use PuffinMail\LifeCycle\Model\Config;
use PuffinMail\LifeCycle\Model\Connection;

/**
 * The PuffinMail storefront script in every page's head (website visits, browse abandonment, signup forms).
 * Only page-level hints are rendered here (the product on a product page), because pages are full-page cached;
 * the signed-in customer comes from the private `puffinmail` customer-data section (CustomerData\Identity).
 */
class Storefront implements ArgumentInterface
{
    /**
     * Storefront constructor.
     *
     * @param Config $config
     * @param Connection $connection
     * @param StoreManagerInterface $storeManager
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * The storefront script URL, or '' when the script is off, not connected or the URL isn't safe to load.
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getScriptUrl(): string
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isSetFlag('storefront/enabled', $storeId)) {
            return '';
        }
        $url = $this->connection->getScriptUrl($storeId);
        // https, or a development portal on the local network.
        return preg_match('#^https://#', $url)
            || preg_match('#^http://(localhost|127\.0\.0\.1|192\.168\.)#', $url) ? $url : '';
    }

    /**
     * Page-level hints for the script: the product on a product page.
     *
     * @return array{product?: array{id: string}}
     */
    public function getPageHints(): array
    {
        if ($this->request instanceof HttpRequest && $this->request->getFullActionName() === 'catalog_product_view') {
            $id = (int) $this->request->getParam('id');
            if ($id > 0) {
                return ['product' => ['id' => (string) $id]];
            }
        }
        return [];
    }
}
