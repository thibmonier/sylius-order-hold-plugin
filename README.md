# Sylius Order Hold Plugin

[![Build](https://github.com/thibmonier/sylius-order-hold-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-order-hold-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**A small shared kernel for Sylius 2 plugins that keep placed orders unpaid for a while: pay on
account (trade credit), purchase approval, manual review.**

Such plugins all need the same four changes to Sylius: keep their orders out of
`sylius:cancel-unpaid-orders`, hide some payment methods everywhere Sylius offers or assigns
one, and stop the customer from paying an order that is not ready to be paid. When two plugins
decorate the same Sylius services on their own, the decorations compete and break each other;
some obvious implementations are also wrong (a guard on the order `cancel` transition makes the
unpaid-orders command loop forever; an extra tagged payment methods resolver *replaces* the
channel-based resolver instead of filtering it).

This package decorates each of these Sylius services **once** and exposes small tagged
contracts instead. It has no entity, no table, no route and no UI. On its own it changes
nothing visible, except that `sylius:cancel-unpaid-orders` can no longer loop forever.

## Features

| Need | What the package does |
|---|---|
| Orders on credit terms or awaiting approval must not be cancelled after 5 days | `UnpaidOrderCancellationExclusionInterface`: return a hold, **indefinite** or **since a date** (the expiration period then runs from that date, e.g. the approval date). One decorator of `sylius.updater.unpaid_orders_state` reads the unpaid orders by increasing id, so held orders and refused or failing cancellations are never read twice: the command always terminates. Each cancellation is flushed on its own; one that throws (e.g. not enough units on hold) is logged and skipped. An application override of `OrderRepository::findOrdersUnpaidSince()` is respected (its result restricts the candidates). |
| Exclude held orders in SQL rather than in PHP | `UnpaidOrderCandidateQueryExtensionInterface` adds conditions to the candidate query (e.g. `NOT EXISTS` on the plugin's table). The base parameters are prefixed `tmonier_oh_`: an extension that overwrites one makes the command fail instead of silently changing the selection. |
| Offer a payment method to some customers only | `PaymentMethodEligibilityCheckerInterface` returns a refusal with a reason (`IneligibilityReason` enum) and a translatable message. Applied to the composite `sylius.resolver.payment_methods` (shop checkout and order page, API choices and validators), to `sylius.resolver.payment_method.default` (method of the payments created by the order processor), to `sylius.provider.payment.order` (a payment created after a failed or cancelled one copies its method: a refused one is replaced by the eligible default), to the API payment method changer (change after checkout), to shop API payment requests (`AddPaymentRequest` / `UpdatePaymentRequest`, the core accepts any method code), at checkout completion (shop order and API `CompleteOrder`), and on the shop pay routes (a method refused since it was chosen sends the customer back to the order page to choose another one). |
| Do not let the customer pay before approval | `PaymentEntryBlockerInterface`: the shop routes that start a payment (`sylius_shop_order_pay`, reached after checkout and from the order page; `sylius_shop_payment_request_pay`; the Payum `payum_capture_do`, `payum_capture_do_session` and `payum_authorize_do` routes, for tokens created before the block) redirect to the thank-you page with a flash message; the shop API refuses to create or update payment requests of the order (422). |
| Know who an order is accountable to | `AccountOwnerResolverInterface`: the customer by default; a company plugin decorates `tmonier_order_hold.resolver.account_owner`. |
| Fail early on an unsupported setup | A compiler pass stops the container build when the checkout, order or payment graph runs on winzou: the hold plugins listen to Symfony Workflow events. Adapters set through environment variables are checked with their build-time value and again at runtime (first request or console command). |

Also included: default messages in English, French, German, Spanish, Italian, Dutch and Polish;
MySQL, MariaDB and PostgreSQL support (Doctrine query builder only).

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ (8.3+ with Sylius 2.3) |
| Sylius | 2.2.9+, 2.3 |
| Symfony | 6.4, 7.4 (Sylius 2.2); 6.4, 7.4, 8.0 (Sylius 2.3) |

Sylius 2.2.9 is the first 2.2 release with the fixes for GHSA-2rv4-pjmm-7fxf and
GHSA-vv4h-q2x8-74g4 (payment requests).

The state machine abstraction must use the **Symfony Workflow** adapter for the
`sylius_order_checkout`, `sylius_order` and `sylius_payment` graphs (the Sylius 2 default).

## Installation

Usually installed as a dependency of another plugin. To install it directly:

1. Require the package:

    ```bash
    composer require tmonier/sylius-order-hold-plugin
    ```

2. Register the bundle in `config/bundles.php`:

    ```php
    Tmonier\SyliusOrderHoldPlugin\TmonierSyliusOrderHoldPlugin::class => ['all' => true],
    ```

No route, no migration, no asset.

## Configuration

Optional, with the defaults:

```yaml
# config/packages/tmonier_sylius_order_hold.yaml
tmonier_sylius_order_hold:
    unpaid_orders:
        # Orders read per query by sylius:cancel-unpaid-orders (the entity manager is cleared after each batch).
        batch_size: 100
    # Fail at container compilation when the checkout, order or payment graph runs on winzou.
    check_state_machine_adapters: true
```

The expiration period itself is the Sylius one (`sylius_order.expiration.order`, 5 days by
default).

## Writing a hold plugin

Implement the interfaces in services with `autoconfigure: true` (tags are added
automatically) or tag them yourself:

| Interface | Tag |
|---|---|
| `UnpaidOrder\UnpaidOrderCancellationExclusionInterface` | `tmonier_order_hold.unpaid_order_cancellation_exclusion` |
| `UnpaidOrder\UnpaidOrderCandidateQueryExtensionInterface` | `tmonier_order_hold.unpaid_order_candidate_query_extension` |
| `PaymentMethod\PaymentMethodEligibilityCheckerInterface` | `tmonier_order_hold.payment_method_eligibility_checker` |
| `PaymentEntry\PaymentEntryBlockerInterface` | `tmonier_order_hold.payment_entry_blocker` |

```php
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHold;

final class ApprovalHold implements UnpaidOrderCancellationExclusionInterface, PaymentEntryBlockerInterface, PaymentMethodEligibilityCheckerInterface
{
    public function getHold(OrderInterface $order): ?UnpaidOrderHold
    {
        $approval = $this->approvals->findForOrder($order);

        return match (true) {
            null === $approval => null,
            $approval->isPending() => UnpaidOrderHold::indefinitely('approval'),
            default => UnpaidOrderHold::since($approval->getApprovedAt(), 'approval'),
        };
    }

    public function getBlock(OrderInterface $order): ?PaymentEntryBlock
    {
        return $this->approvals->isPending($order) ? new PaymentEntryBlock('app.order.awaiting_approval') : null;
    }

    public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility
    {
        return $this->isOnAccount($paymentMethod) && !$this->canBuyOnAccount($payment)
            ? new PaymentMethodIneligibility(IneligibilityReason::CreditLimitExceeded, null, ['%available%' => '120.00 EUR'])
            : null;
    }
}
```

Rules:

- **Never guard the `sylius_order.cancel` transition** to protect an order: return a hold. The
  updater skips refused cancellations anyway (logged as a warning) but other code paths
  (admin cancellation) must keep working.
- **Never tag an extra `sylius.payment_method_resolver`** to filter methods: the core composite
  returns the methods of the first resolver that supports the payment.
- **Do not decorate** the services listed below in your plugin; implement a checker instead.
- Checkers are called often (every payment method list): keep them cheap, cache per request.
- Query extensions must not use parameter names starting with `tmonier_oh_`.
- Messages are translation keys: `validators` domain for refusals and API blocks, `flashes`
  domain for the shop block. Without a message, the reason's default message is used
  (`tmonier_order_hold.payment_method.ineligible.<reason>`, parameter `%paymentMethodName%`).

Decorated services (each exactly once):

| Service | Decorator |
|---|---|
| `sylius.updater.unpaid_orders_state` | `tmonier_order_hold.updater.unpaid_orders_state` |
| `sylius.resolver.payment_methods` (composite) | `tmonier_order_hold.resolver.payment_methods` |
| `sylius.resolver.payment_method.default` | `tmonier_order_hold.resolver.default_payment_method` |
| `sylius.provider.payment.order` | `tmonier_order_hold.provider.order_payment` |
| `sylius_api.changer.payment_method` (when the API bundle is enabled) | `tmonier_order_hold.changer.payment_method` |

Aggregates you can inject: `UnpaidOrderHoldResolverInterface` (is an order held? since when?),
`PaymentMethodEligibilityCheckerInterface` (all checkers), `PaymentEntryBlockerInterface` (all
blockers), `AccountOwnerResolverInterface`.

## Limitations

- The unpaid-orders updater replaces the core algorithm (same selection, keyset pagination) and
  does not call the decorated service. Another decoration of
  `sylius.updater.unpaid_orders_state` applied *before* this one is ignored. A repository that
  overrides `findOrdersUnpaidSince()` is called once per run without limit (its ids then restrict
  the paginated query), which loads all its candidates in memory once.
- Eligibility is checked where Sylius offers, assigns or validates a payment method. Code that
  sets `Payment::setMethod()` directly (custom admin tools, imports) bypasses it.
- The admin side is untouched: administrators can still change payment methods and complete
  payments of held orders, on purpose.
- A blocked shop pay page redirects to the thank-you page; if the session cannot be written
  (stateless clients), the thank-you page falls back to the homepage as in Sylius.
- Payment gateways that capture during checkout, before the pay page, are not stopped by the
  payment entry blocker: use payment method eligibility for them. Gateway notifications
  (server-to-server) are never blocked: a payment the gateway already took is recorded.

## Development

```bash
docker compose up -d mysql mailhog   # host ports in compose.override.yml (gitignored)
composer database-reset              # dev database with fixtures (prefix with APP_ENV=test for tests)
vendor/bin/phpunit                   # unit, integration (cron) and functional (shop + API) tests
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

See [SPEC.md](SPEC.md) for the research and design decisions.

## License

MIT, see [LICENSE](LICENSE).
