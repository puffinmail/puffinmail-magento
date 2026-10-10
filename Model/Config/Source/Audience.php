<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Who an email event may go to; sent as the event's `notify`. PuffinMail checks the newsletter status itself.
 */
class Audience implements OptionSourceInterface
{
    /**
     * Audience options: newsletter subscribers only, or everyone with a known email.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'subscribed', 'label' => __('Newsletter subscribers only')],
            ['value' => 'always', 'label' => __('Everyone with a known email (inferred consent)')],
        ];
    }
}
