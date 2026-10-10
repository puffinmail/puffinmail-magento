<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;

/**
 * Runs code as the storefront of a store view, so product URLs, images and prices come out as customers see
 * them. Nested calls for the same store view reuse the running emulation (Magento doesn't allow nesting).
 */
class StoreEmulation
{
    /**
     * The store view being emulated, or null when none is.
     *
     * @var int|null
     */
    private ?int $current = null;

    /**
     * StoreEmulation constructor.
     *
     * @param Emulation $emulation
     */
    public function __construct(
        private readonly Emulation $emulation
    ) {
    }

    /**
     * Runs $callback under frontend emulation for the store view and returns its result.
     *
     * @template T
     * @param int $storeId
     * @param callable $callback
     * @phpstan-param callable(): T $callback
     * @return T
     */
    public function run(int $storeId, callable $callback): mixed
    {
        if ($this->current === $storeId) {
            return $callback();
        }
        $previous = $this->current;
        if ($previous !== null) {
            $this->emulation->stopEnvironmentEmulation();
        }
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        $this->current = $storeId;
        try {
            return $callback();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
            $this->current = null;
            if ($previous !== null) {
                $this->emulation->startEnvironmentEmulation($previous, Area::AREA_FRONTEND, true);
                $this->current = $previous;
            }
        }
    }
}
