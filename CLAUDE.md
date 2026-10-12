# CLAUDE.md

Sylius 2 plugin: B2B quick order (paste / CSV, preview, bulk add with one order processing),
reorder, shop API bulk add. Functional spec and decisions: `SPEC.md`. Public docs: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console lint:twig templates
vendor/bin/console lint:yaml config translations tests/TestApplication/config --parse-tags
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3325, MailHog on
8044/1044), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_quick_order_dev` / `_test`,
both loaded with the default fixture suite plus the demo data of
`tests/TestApplication/config/config.yaml` (T-shirt `QO-TEE-*`, buyer
`quickorder@example.com` / `sylius` with 2 orders). Dev server:
`symfony server:start -d --port=8091 --no-tls` (admin `sylius` / `sylius`); stop it after use.
`doctrine:schema:validate` reports drift on core tables only (payment request, messenger);
the plugin has no table, CI runs `--skip-sync`.

## Architecture

- `Input`: `PasteParser`, `CsvParser`, `LineCollector` → `QuickOrderInput` (lines + invalid
  lines + whole-input flags; JSON round trip for the preview form, re-validated).
- `Adder/BulkCartAdder`: the engine (one variant query via `VariantLookup`, checks, one
  `process()`, statuses after processing, all-or-nothing revert, one flush, events
  `tmonier_quick_order.pre_bulk_add` / `post_bulk_add`). `LineStatus` enum, `LineResult`,
  `BulkAddResult` (array round trip for the session).
- `Cart/LockedCartCheckerInterface` (tag `tmonier_quick_order.locked_cart_checker`,
  `CompositeLockedCartChecker`), `QuoteRequestCartChecker` bridge registered by
  `DependencyInjection/Compiler/QuoteRequestBridgePass` when
  `tmonier_quote_request.quote_cart_checker` exists (the test app defines a fake one: cart
  notes `QUOTE CART`).
- `Quantity/QuantityConstraintProviderInterface` (service
  `tmonier_quick_order.quantity_constraint_provider`, null default) + `quantity_rounding`.
- `Controller/Shop/QuickOrderController`: index, preview, add, result, template, report,
  reorder (all CSRF id `tmonier_quick_order`). Templates in `templates/shop`.
- `Api`: `Resource/BulkAddItemsToCart` (API Platform attributes, mapping path prepended only
  with API Platform), `BulkAddItemsProcessor` (cart access rule copied from the core),
  `BulkAddItemsResult`.
- Grid action `tmonier_quick_order_reorder` on `sylius_shop_account_order`
  (`config/app/grid.yaml`), hooks in `config/twig_hooks/shop.yaml`, account menu listener.
- Order matrix (v1.1): `Matrix/` (`MatrixActivationChecker`, `MatrixBuilder` → `Matrix` /
  `MatrixCell`, `MatrixOrderHandler` → bulk adder), live component
  `Twig/Component/MatrixOrderComponent` (template `templates/shop/product/matrix.html.twig`,
  quantities keyed `v<variant id>`, prop `lastResult`), wrapper `templates/shop/product/add_to_cart.html.twig`
  hiding the core form, Twig function `tmonier_quick_order_is_matrix`, fallback action
  `QuickOrderController::matrixAddAction`, `CatalogPriceCalculatorPass` (2.3 catalog calculator,
  2.2 fallback). Live component tests use `InteractsWithLiveComponents` with the test client.
  No-JS results come back through the session (`MatrixOrderComponent::SESSION_RESULT_KEY`).
  Our hooks inside hookable templates pass `_prefixes: []` (absolute names); the test app
  registers test templates on them and switches guests off with `TMONIER_QUICK_ORDER_ALLOW_GUESTS`.
- `Fixture/QuickOrderDemoCatalogFixture` (`tmonier_quick_order_demo_catalog`, idempotent).

## Rules

- No core entity change; the plugin has no table.
- Labels in `messages`, flashes and refusals in `flashes`; every key in en, fr, de, es, it,
  nl, pl (`TranslationsTest`). Clear the cache (dev and test) after adding a translation file.
- Never re-dispatch `sylius.cart_item_add` (core listener would add every line again).
- Compare channels by code and variants by id (code when not persisted), never by identity.
- Do not disclose stock counts or names of unpublished products; visitor carts get
  `not_available` for every unusable code (`BulkCartAdder::check()`).
- Quantities are plain integers (`LineCollector::parseQuantity()`); never guess separators.
- The previewed input lives in the session (`QuickOrderController::PREVIEW_KEY` + nonce).
- Functional tests: one kernel, no reboot, one transaction rolled back after each test. The
  entity manager is cleared between requests: reload entities before changing them. The
  test app resets the shop "new cart" context on each request
  (`tests/TestApplication/src/Cart/ResetNewCartOnRequestListener`). Fixtures are random:
  `tests/TestCatalog.php` creates products, the T-shirt matrix, promotions, customers and
  placed orders; never skip a test on fixtures (`NoSkippedTestsTest`).
