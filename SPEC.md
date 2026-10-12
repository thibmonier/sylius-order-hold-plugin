# Specification — order-hold kernel

Shared package of the tmonier B2B plugins that leave placed orders unpaid for a long time
(trade credit, purchase approval). Decided in the B2B memo of 2026-10-11 (section
"Dépendances et noyau commun"): no heavy `b2b-core` with entities, but a small kernel with
contracts and single decorators, no entity and no UI.

## 1. Problems found in Sylius 2.2 / 2.3

All service ids and classes below were checked in Sylius 2.3.0 (vendor) and on the `2.2`
branch on GitHub (2026-10-11).

1. **Unpaid orders cancellation.** `sylius:cancel-unpaid-orders` calls
   `sylius.updater.unpaid_orders_state` (`Sylius\Component\Core\Updater\UnpaidOrdersStateUpdater`,
   identical in 2.2 and 2.3), which loops
   `while ([] !== $orders = $repository->findOrdersUnpaidSince($terminalDate, $batchSize))`
   and applies `sylius_order.cancel` to each order, catching `StateMachineExecutionException`.
   The query selects `checkoutState = completed`, `paymentState = awaiting_payment`,
   `state = new`, `checkoutCompletedAt < now - sylius_order.order_expiration_period` (5 days).
   Consequence: an order that is not cancelled (guard on `cancel`, exception) is returned again
   by the same query, and the command never ends. Orders on 30-day terms are cancelled after
   5 days.
   https://github.com/Sylius/Sylius/blob/2.3/src/Sylius/Component/Core/Updater/UnpaidOrdersStateUpdater.php
2. **Payment methods resolver.** `sylius.resolver.payment_methods` is a
   `CompositeMethodsResolver` over the `sylius.payment_method_resolver` tag
   (`channel_based`, priority 1; `default`, priority 0). It returns the methods of the **first**
   resolver that supports the payment: an extra tagged resolver replaces the channel-based one.
   Consumers: `PaymentMethodChoiceType` (shop checkout and order page), the API
   `ChosenPaymentMethodEligibility` validator, the API payment methods collection provider
   (carts only), `sylius_api.changer.payment_method`, the shop Twig extension, the
   order-payment-method-selection-requirement checker.
3. **Default payment method.** `sylius.resolver.payment_method.default`
   (`DefaultPaymentMethodResolver`) returns `findEnabledForChannel($channel)[0]` without the
   resolver; it is used by `sylius.provider.payment.order`, so the order processor can assign a
   method that the customer may not use.
4. **Change after checkout (API).** `sylius_api.changer.payment_method` (`PaymentMethodChanger`,
   used by `ChoosePaymentMethodHandler` for placed orders and by `ChangePaymentMethodHandler`)
   checks the resolver only when it is injected (nullable argument; injected in 2.2 and 2.3).
5. **Checkout completion.** The core `OrderPaymentMethodEligibility` constraints (shop `Order`,
   API `CompleteOrder`, group `sylius_checkout_complete`) only check that the method is enabled
   in the channel: a method selected before it became ineligible is accepted.
6. **Pay entry.** `sylius_shop_order_pay` (`/{_locale}/order/{tokenValue}/pay`) is reached by a
   route option (`redirect`) of `sylius_shop_checkout_complete` and `sylius_shop_order_show`.
   Its controller `OrderPayController::payAction` iterates the
   `sylius_shop.provider.order_pay.pay_response` providers, but `PayResponseProviderInterface`
   takes a `RequestConfiguration` in 2.2 (experimental) and a `Request` in 2.3: no single
   implementation works on both. Shop API: `POST /api/v2/shop/orders/{tokenValue}/payment-requests`
   (`AddPaymentRequest`, validation group `sylius`) and `PUT /api/v2/shop/payment-requests/{hash}`
   (`UpdatePaymentRequest`).
   https://github.com/Sylius/Sylius/blob/2.3/src/Sylius/Bundle/ShopBundle/Resources/config/routing/checkout.yml
7. `OrderRepository::findOneByTokenValue()` excludes carts: validators that may receive a cart
   use `findOneBy(['tokenValue' => ...])`, as the core validators do.

## 2. Design

| Problem | Decision |
|---|---|
| 1 | Decorate `sylius.updater.unpaid_orders_state` with `HoldAwareUnpaidOrdersStateUpdater`: same selection, ordered by id, `id > lastId` (keyset pagination) so every order is read at most once; `can()` before `apply()`; flush + clear per batch like the core. Exclusions return `UnpaidOrderHold::indefinitely()` or `::since($date)` (expiration counted from that date: approval date). An indefinite hold wins, otherwise the latest date. Optional `UnpaidOrderCandidateQueryExtensionInterface` for SQL-level exclusion. The decorated service is not called. |
| 2 | Decorate the composite (not a tagged resolver): `EligiblePaymentMethodsResolver` filters its result with the composite eligibility checker. Every consumer of the composite is covered. |
| 3 | Decorate the default resolver: keep the core choice when eligible, otherwise the first eligible method of the (filtered) composite, otherwise `UnresolvedDefaultPaymentMethodException` (same contract as the core). |
| 4 | Decorate `sylius_api.changer.payment_method` (ignored when the API bundle is disabled) and throw `PaymentMethodCannotBeChangedException` for an ineligible method, whatever the core wiring. |
| 5 | Class constraint `EligiblePaymentMethods` on `Sylius\Component\Core\Model\Order` and on the API `CompleteOrder` command (group `sylius_checkout_complete`): one violation per pending payment (`cart`/`new`) with an ineligible method, message from the checker, code = reason. |
| 6 | `kernel.request` listener (priority 0, after router, locale and firewall) on the `sylius_shop_order_pay` route: blocked order → `sylius_order_id` in session (as `payAction`), `info` flash, redirect to `sylius_shop_order_thank_you`. API: class constraint `PaymentEntryAllowed` on `AddPaymentRequest` and `UpdatePaymentRequest` (group `sylius`) → 422. |
| Owner | `AccountOwnerResolverInterface` (`tmonier_order_hold.resolver.account_owner`), customer by default, replaced by decoration (one owner model per shop), returns an `AccountOwner` value (type, identifier, subject). |
| Winzou | Compiler pass: the container build fails when `sylius_order_checkout`, `sylius_order` or `sylius_payment` resolves to `winzou_state_machine` (mapping or default adapter); can be disabled. |

### Review fixes (2026-10-12)

- The core `AddPaymentRequestHandler` loads any method by code: `EligiblePaymentRequestMethod`
  checks the method of `AddPaymentRequest` (payment via `findOneByOrderToken`) and of the
  payment request of `UpdatePaymentRequest`.
- `OrderPaymentProvider::provideOrderPayment()` overwrites the default resolver's choice with
  the method of the last cancelled / failed payment: `sylius.provider.payment.order` is decorated
  and replaces a refused method by the eligible default (kept when none is eligible).
- The payment entry listener also covers `sylius_shop_payment_request_pay` and the Payum
  capture / authorize routes (token → payment through the Payum storages, token untouched), and
  checks the eligibility of the payment (or payment request) method: refused → order page.
- Cron: `\Throwable` per order (e.g. `NotEnoughUnitsOnHoldException` from the inventory release
  on cancel), each cancellation flushed on its own, entity manager cleared after a failure, next
  batch from the failed id; rethrown only when the entity manager is closed.
- An override of `findOrdersUnpaidSince()` (declaring class other than the core repository)
  restricts the candidates (`o.id IN (...)`); base parameters prefixed `tmonier_oh_` and checked
  after each query extension.
- Reverification (2026-10-12): a failure inside the flush closes the entity manager: reset
  through `ManagerRegistry` and continue after the failed id. Payum capture / authorize URLs are
  also the browser return URL of offsite gateways: only a payment in state `new` without gateway
  details is intercepted, and the ineligibility redirect only applies to `new` payments.
  Redirects use the request locale when the channel offers it, else the channel default locale.
- Winzou check: env placeholders resolved at build time when possible, runtime listener
  (`kernel.request`, `console.command`) whenever env vars are involved.

Extension points are tagged and autoconfigured; the aggregates (`Composite*`) are not tagged
(explicit service definitions). Refusals carry an `IneligibilityReason` (`restricted`,
`awaiting_approval`, `credit_limit_exceeded`, `credit_hold`, `overdue`, `other`), a message
(validators domain, defaulting to the reason's message) and parameters.

Name: the research used "order-hold-kernel"; the package follows the tmonier naming
(`tmonier/sylius-order-hold-plugin`, type `sylius-plugin`) since it is a Symfony bundle.

## 3. Out of scope

- Deferring invoicing until approval (`OrderPlaced` of sylius/invoicing-plugin): optional later
  addition, not needed by trade credit v1 (an order on account is invoiced immediately).
- Admin UI, entities, migrations.
- Checks on `Payment::setMethod()` calls outside the Sylius services listed above.

## 4. Tests

- Unit: hold resolution, resolvers, changer, listener, validators, compiler pass, extension
  (each Sylius service decorated once, autoconfiguration), translations (7 locales, same keys
  and placeholders).
- Integration (test application, MySQL, batch size 2): held orders are not cancelled and the
  command terminates with more held orders than the batch size; expiration from a hold date; a
  workflow guard refusing `cancel` does not make the command loop; the order processor never
  assigns a refused default method; the composite and the API changer refuse ineligible
  methods.
- Functional: shop checkout payment step and order page (post-checkout change) list only
  eligible methods; shop checkout completion refused with the checker message; blocked pay page
  redirects to the thank-you page with the flash, unblocked orders go through the regular
  payment; API payment methods of a cart, post-checkout change (422), `complete` (422 with the
  message), payment request creation (422 when blocked, 201 otherwise).
- Every test runs in a rolled-back transaction and creates its own zone, shipping and payment
  methods, product, customer and orders (the Sylius fixtures are random).
