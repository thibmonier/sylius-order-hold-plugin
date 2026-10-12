<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwnerResolverInterface;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\CustomerAccountOwnerResolver;
use Tmonier\SyliusOrderHoldPlugin\DependencyInjection\TmonierSyliusOrderHoldExtension;
use Tmonier\SyliusOrderHoldPlugin\EventListener\PaymentEntryBlockingListener;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\CompositePaymentEntryBlocker;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\CompositePaymentMethodEligibilityChecker;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligibilityAwarePaymentMethodChanger;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligibleDefaultPaymentMethodResolver;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligibleOrderPaymentProvider;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\EligiblePaymentMethodsResolver;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\HoldAwareUnpaidOrdersStateUpdater;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHoldResolver;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHoldResolverInterface;
use Tmonier\SyliusOrderHoldPlugin\Validator\EligiblePaymentMethodsValidator;
use Tmonier\SyliusOrderHoldPlugin\Validator\EligiblePaymentRequestMethodValidator;
use Tmonier\SyliusOrderHoldPlugin\Validator\PaymentEntryAllowedValidator;

// Every Sylius service is decorated exactly once, here: plugins that hold orders register tagged
// exclusions, checkers and blockers instead of decorating these services themselves.
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Unpaid orders cancellation (sylius:cancel-unpaid-orders)

    $services
        ->set('tmonier_order_hold.resolver.unpaid_order_hold', UnpaidOrderHoldResolver::class)
        ->args([tagged_iterator(TmonierSyliusOrderHoldExtension::TAG_UNPAID_ORDER_CANCELLATION_EXCLUSION)])
    ;
    $services->alias(UnpaidOrderHoldResolverInterface::class, 'tmonier_order_hold.resolver.unpaid_order_hold');

    $services
        ->set('tmonier_order_hold.updater.unpaid_orders_state', HoldAwareUnpaidOrdersStateUpdater::class)
        ->decorate('sylius.updater.unpaid_orders_state')
        ->args([
            service('sylius.manager.order'),
            service('sylius.repository.order'),
            param('sylius.model.order.class'),
            service('sylius_abstraction.state_machine'),
            service('tmonier_order_hold.resolver.unpaid_order_hold'),
            tagged_iterator(TmonierSyliusOrderHoldExtension::TAG_UNPAID_ORDER_CANDIDATE_QUERY_EXTENSION),
            param('sylius_order.order_expiration_period'),
            service('logger'),
            param('tmonier_order_hold.unpaid_orders.batch_size'),
        ])
        ->tag('monolog.logger', ['channel' => 'tmonier_order_hold'])
    ;

    // Payment method eligibility

    $services
        ->set('tmonier_order_hold.checker.payment_method_eligibility', CompositePaymentMethodEligibilityChecker::class)
        ->args([tagged_iterator(TmonierSyliusOrderHoldExtension::TAG_PAYMENT_METHOD_ELIGIBILITY_CHECKER)])
    ;
    $services->alias(PaymentMethodEligibilityCheckerInterface::class, 'tmonier_order_hold.checker.payment_method_eligibility');

    // Shop checkout and order page (PaymentMethodChoiceType), API choices and validators, API changer.
    $services
        ->set('tmonier_order_hold.resolver.payment_methods', EligiblePaymentMethodsResolver::class)
        ->decorate('sylius.resolver.payment_methods')
        ->args([
            service('.inner'),
            service('tmonier_order_hold.checker.payment_method_eligibility'),
        ])
    ;

    // Method of the payments created by the order processor.
    $services
        ->set('tmonier_order_hold.resolver.default_payment_method', EligibleDefaultPaymentMethodResolver::class)
        ->decorate('sylius.resolver.payment_method.default')
        ->args([
            service('.inner'),
            service('sylius.resolver.payment_methods'),
            service('tmonier_order_hold.checker.payment_method_eligibility'),
        ])
    ;

    // Payment created after a failed or cancelled one: the core copies the previous method.
    $services
        ->set('tmonier_order_hold.provider.order_payment', EligibleOrderPaymentProvider::class)
        ->decorate('sylius.provider.payment.order')
        ->args([
            service('.inner'),
            service('sylius.resolver.payment_method.default'),
            service('tmonier_order_hold.checker.payment_method_eligibility'),
        ])
    ;

    // Payment method change of a placed order (shop API); dropped when the API bundle is disabled.
    $services
        ->set('tmonier_order_hold.changer.payment_method', EligibilityAwarePaymentMethodChanger::class)
        ->decorate('sylius_api.changer.payment_method', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([
            service('.inner'),
            service('sylius.repository.payment'),
            service('sylius.repository.payment_method'),
            service('tmonier_order_hold.checker.payment_method_eligibility'),
        ])
    ;

    $services
        ->set('tmonier_order_hold.validator.eligible_payment_methods', EligiblePaymentMethodsValidator::class)
        ->args([
            service('sylius.repository.order'),
            service('tmonier_order_hold.checker.payment_method_eligibility'),
        ])
        ->tag('validator.constraint_validator', ['alias' => 'tmonier_order_hold.validator.eligible_payment_methods'])
    ;

    $services
        ->set('tmonier_order_hold.validator.eligible_payment_request_method', EligiblePaymentRequestMethodValidator::class)
        ->args([
            service('sylius.repository.payment'),
            service('sylius.repository.payment_method'),
            service('sylius.repository.payment_request'),
            service('tmonier_order_hold.checker.payment_method_eligibility'),
        ])
        ->tag('validator.constraint_validator', ['alias' => 'tmonier_order_hold.validator.eligible_payment_request_method'])
    ;

    // Payment entry

    $services
        ->set('tmonier_order_hold.blocker.payment_entry', CompositePaymentEntryBlocker::class)
        ->args([tagged_iterator(TmonierSyliusOrderHoldExtension::TAG_PAYMENT_ENTRY_BLOCKER)])
    ;
    $services->alias(PaymentEntryBlockerInterface::class, 'tmonier_order_hold.blocker.payment_entry');

    $services
        ->set('tmonier_order_hold.listener.payment_entry_blocking', PaymentEntryBlockingListener::class)
        ->args([
            service('sylius.repository.order'),
            service('sylius.repository.payment_request'),
            service('tmonier_order_hold.blocker.payment_entry'),
            service('tmonier_order_hold.checker.payment_method_eligibility'),
            service('router'),
            service('payum')->nullOnInvalid(),
        ])
        // After the router (32), the locale listeners (16) and the firewall (8).
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 0])
    ;

    $services
        ->set('tmonier_order_hold.validator.payment_entry_allowed', PaymentEntryAllowedValidator::class)
        ->args([
            service('sylius.repository.order'),
            service('sylius.repository.payment_request'),
            service('tmonier_order_hold.blocker.payment_entry'),
        ])
        ->tag('validator.constraint_validator', ['alias' => 'tmonier_order_hold.validator.payment_entry_allowed'])
    ;

    // Account owner

    $services->set('tmonier_order_hold.resolver.account_owner', CustomerAccountOwnerResolver::class);
    $services->alias(AccountOwnerResolverInterface::class, 'tmonier_order_hold.resolver.account_owner');
};
