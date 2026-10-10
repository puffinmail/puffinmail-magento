<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;

/**
 * Active cart price rules that use a coupon (specific or auto-generated).
 */
class CartRule implements OptionSourceInterface
{
    /**
     * CartRule constructor.
     *
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * "No coupon" plus each active coupon rule, by name.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [['value' => 0, 'label' => __('-- No coupon --')]];

        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('coupon_type', ['in' => [Rule::COUPON_TYPE_SPECIFIC, Rule::COUPON_TYPE_AUTO]])
            ->setOrder('name', 'ASC');

        foreach ($collection as $rule) {
            $options[] = [
                'value' => (int) $rule->getId(),
                'label' => sprintf('%s (#%d)', $rule->getName(), $rule->getId()),
            ];
        }
        return $options;
    }
}
