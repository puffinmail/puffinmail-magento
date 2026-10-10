<?php
declare(strict_types=1);

namespace PuffinMail\LifeCycle\Model;

use Magento\SalesRule\Api\CouponManagementInterface;
use Magento\SalesRule\Api\Data\CouponGenerationSpecInterfaceFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;

/**
 * Turns a configured cart price rule into the `coupon` of an email event:
 *  - auto-generation rules get a fresh code (inheriting the rule's uses-per-coupon and end date);
 *  - specific-coupon rules return their one shared code;
 *  - anything else (no rule, inactive, no-coupon rule) returns null.
 */
class CouponProvider
{
    public const TEST_CODE = 'TESTCODE';

    /**
     * CouponProvider constructor.
     *
     * @param RuleFactory $ruleFactory
     * @param CouponManagementInterface $couponManagement
     * @param CouponGenerationSpecInterfaceFactory $specFactory
     * @param Logger $logger
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly CouponManagementInterface $couponManagement,
        private readonly CouponGenerationSpecInterfaceFactory $specFactory,
        private readonly Logger $logger
    ) {
    }

    /**
     * The coupon for a cart price rule: code, amount, type and expiry, or null if the rule gives none.
     *
     * @param int $ruleId
     * @param string $prefix prefix for generated codes
     * @param bool $test describe the rule's coupon with code TESTCODE instead of generating one
     * @return array{code: string, amount: float, type: string, expires_at: ?string}|null
     */
    public function create(int $ruleId, string $prefix = '', bool $test = false): ?array
    {
        if ($ruleId <= 0) {
            return null;
        }
        $rule = $this->ruleFactory->create()->load($ruleId);
        if (!$rule->getId() || !$rule->getIsActive()) {
            return null;
        }

        try {
            $code = $test ? self::TEST_CODE : $this->code($rule, $prefix);
        } catch (\Throwable $e) {
            $this->logger->error('Coupon for cart price rule ' . $ruleId . ' failed: ' . $e->getMessage());
            return null;
        }
        if ($code === null) {
            return null;
        }

        $toDate = (string) $rule->getToDate();
        return [
            'code' => $code,
            'amount' => round((float) $rule->getDiscountAmount(), 2),
            'type' => $rule->getSimpleAction() === Rule::BY_PERCENT_ACTION ? 'percent' : 'fixed',
            'expires_at' => $toDate !== ''
                ? gmdate('c', (int) strtotime(substr($toDate, 0, 10) . ' 23:59:59 UTC'))
                : null,
        ];
    }

    /**
     * A fresh code for an auto-generation rule, the shared code for a specific-coupon rule, otherwise null.
     *
     * @param Rule $rule
     * @param string $prefix
     * @return string|null
     */
    private function code(Rule $rule, string $prefix): ?string
    {
        if ((int) $rule->getCouponType() === Rule::COUPON_TYPE_AUTO || $rule->getUseAutoGeneration()) {
            $spec = $this->specFactory->create();
            $spec->setRuleId((int) $rule->getId());
            $spec->setQuantity(1);
            $spec->setLength(8);
            $spec->setFormat('alphanum');
            if ($prefix !== '') {
                $spec->setPrefix($prefix);
            }
            $codes = $this->couponManagement->generate($spec);
            return $codes ? (string) reset($codes) : null;
        }
        if ((int) $rule->getCouponType() === Rule::COUPON_TYPE_SPECIFIC) {
            return (string) $rule->getPrimaryCoupon()->getCode() ?: null;
        }
        return null;
    }
}
