# CLAUDE.md

Sylius 2 shared kernel for plugins that hold placed orders unpaid (trade credit, purchase
approval): contracts + one decorator per Sylius service, no entity, no route, no UI.
Research and decisions: `SPEC.md`. Public docs and extension guide: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # integration + functional, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console sylius:cancel-unpaid-orders -v
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3327, MailHog on
8046/1046), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_order_hold_dev` / `_test`, both
loaded with the default fixture suite. Dev server: `symfony server:start -d --port=8093 --no-tls`
(admin `sylius` / `sylius`, shop `shop@example.com` / `sylius`); nothing is visible unless a
checker is registered (see `TestOrderHolds`).

## Architecture

- `config/services.php`: the only place where Sylius services are decorated
  (`sylius.updater.unpaid_orders_state`, `sylius.resolver.payment_methods`,
  `sylius.resolver.payment_method.default`, `sylius_api.changer.payment_method`).
  `TmonierSyliusOrderHoldExtensionTest` asserts that list.
- `UnpaidOrder/`: `HoldAwareUnpaidOrdersStateUpdater` (keyset pagination, never re-reads an
  order), `UnpaidOrderHoldResolver` (indefinite hold wins, else latest date), exclusion and
  query-extension contracts.
- `PaymentMethod/`: eligibility contract + `IneligibilityReason`, composite checker, the three
  decorators.
- `PaymentEntry/` + `EventListener/PaymentEntryBlockingListener` (kernel.request on
  `sylius_shop_order_pay`; the pay response provider interface differs between 2.2 and 2.3).
- `Validator/` + `config/validation/*.xml`: `EligiblePaymentMethods` (shop Order and API
  CompleteOrder, group `sylius_checkout_complete`), `PaymentEntryAllowed` (API
  Add/UpdatePaymentRequest, group `sylius`). Use `findOneBy(['tokenValue' => ...])`:
  `findOneByTokenValue()` excludes carts.
- `AccountOwner/`: owner resolver (customer by default, replaced by decoration).
- `DependencyInjection/Compiler/StateMachineAdapterCheckPass`: refuses winzou for the
  checkout, order and payment graphs.
- Test double: `tests/TestApplication/src/OrderHold/TestOrderHolds` (public service
  `tmonier_order_hold.test.order_holds`) implements the three tagged contracts; tests drive it.
  `tests/Functional/OrderHoldTestTrait` builds carts and orders through the real checkout
  state machine inside the rolled-back transaction (own zone, shipping and payment methods,
  product, customer). The test application sets `unpaid_orders.batch_size: 2`.
