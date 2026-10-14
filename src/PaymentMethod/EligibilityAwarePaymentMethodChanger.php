<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\PaymentMethod;

use Sylius\Bundle\ApiBundle\Changer\PaymentMethodChangerInterface;
use Sylius\Bundle\ApiBundle\Exception\PaymentMethodCannotBeChangedException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;

/**
 * Decorates the API `sylius_api.changer.payment_method` (payment method change of a placed
 * order, shop API). The core changer checks the payment methods resolver only when it is
 * injected; this decorator refuses ineligible methods whatever the wiring.
 */
final readonly class EligibilityAwarePaymentMethodChanger implements PaymentMethodChangerInterface
{
    /**
     * @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        private PaymentMethodChangerInterface $decorated,
        private PaymentRepositoryInterface $paymentRepository,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private PaymentMethodEligibilityCheckerInterface $eligibilityChecker,
    ) {
    }

    public function changePaymentMethod(string $paymentMethodCode, mixed $paymentId, OrderInterface $order): OrderInterface
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $paymentMethodCode]);
        $payment = $this->paymentRepository->findOneByOrderId($paymentId, $order->getId());

        if (
            $paymentMethod instanceof PaymentMethodInterface &&
            $payment instanceof PaymentInterface &&
            $this->eligibilityChecker->getIneligibility($paymentMethod, $payment) instanceof \Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility
        ) {
            throw new PaymentMethodCannotBeChangedException();
        }

        return $this->decorated->changePaymentMethod($paymentMethodCode, $paymentId, $order);
    }
}
