<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\Validator;

use Sylius\Bundle\ApiBundle\Command\Payment\AddPaymentRequest;
use Sylius\Bundle\ApiBundle\Command\Payment\UpdatePaymentRequest;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;

final class PaymentEntryAllowedValidator extends ConstraintValidator
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PaymentRequestRepositoryInterface $paymentRequestRepository,
        private readonly PaymentEntryBlockerInterface $paymentEntryBlocker,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PaymentEntryAllowed) {
            throw new UnexpectedTypeException($constraint, PaymentEntryAllowed::class);
        }

        $order = match (true) {
            $value instanceof AddPaymentRequest => $this->orderRepository->findOneByTokenValue($value->orderTokenValue),
            $value instanceof UpdatePaymentRequest => $this->paymentRequestRepository->find($value->hash)?->getPayment()->getOrder(),
            default => null,
        };
        if (!$order instanceof OrderInterface) {
            return;
        }

        $block = $this->paymentEntryBlocker->getBlock($order);
        if (null !== $block) {
            $this->context->buildViolation($block->getMessage(), $block->getParameters())->addViolation();
        }
    }
}
