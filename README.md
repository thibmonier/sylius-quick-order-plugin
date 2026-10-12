# Sylius Quick Order Plugin

[![Build](https://github.com/thibmonier/sylius-quick-order-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-quick-order-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Order by product code for Sylius 2: paste lines or upload a CSV file, preview, then add
up to hundreds of lines to the cart at once. Reorder past orders. Bulk add through the shop API.**

B2B buyers know their references. Adding 80 products one product page at a time is slow,
and adding them one by one through the cart re-processes the whole order on every line.
This plugin gives buyers a quick order page, an "Order again" button and an API endpoint
that add every line with a single order processing. Free and open source.

## Features

| Need | What the plugin does |
|---|---|
| Order from a list of references | **Quick order page** (`/{locale}/quick-order`): paste `CODE;qty`, `CODE,qty` or `CODE<TAB>qty` lines (a copy from a spreadsheet works as is), or `CODE qty` (space before the quantity only, codes may contain spaces). A header line (`SKU`, `code`...) is skipped. |
| Import a file | **CSV upload**: UTF-8 with or without BOM, or Windows-1252; `,`, `;` or TAB detected; LF, CRLF or CR line endings; `SKU` / `QTY` header columns in any order (also `code`, `quantity`, `référence`, `Menge`, `Anzahl`...); a header with other names = code then quantity; no header = code then quantity. A named code column without any quantity column refuses the file instead of guessing. A **CSV template** can be downloaded. |
| Know what will happen before it happens | **Preview** with a status per line: ready, insufficient stock (counting what is already in the cart), quantity above the cart line limit, quantity rule of the product, invalid quantity; for logged-in customers also unknown code, unavailable (product or variant disabled), not sold in this channel, no price. Then one **"Add N lines"** button. |
| Never lose the failing lines | **Error report** (CSV, formulas neutralised) with the lines that were not added, ready to fix and upload again. |
| Choose the behaviour | **Partial** (add the valid lines, report the others; default) or **all or nothing**. |
| Buy the same things again | **Order again** button on the customer's order page and in the order list. Removed, disabled or unpriced variants are reported, the other lines are added at today's prices. |
| Integrate an ERP, a punch-out or a SPA | **`POST /api/v2/shop/orders/{tokenValue}/bulk-items`** with the same checks and a status per line. |
| Stay fast | Duplicates are merged, variants are loaded in **one query**, items are merged into existing cart lines like the core does, the order is **processed once and flushed once**, whatever the number of lines. |

Also included: limits on lines, units and size per request; translations in English, French, German, Spanish, Italian, Dutch and Polish;
links from the cart page and the customer account menu; no JavaScript needed; events for
your own rules; MySQL, MariaDB and PostgreSQL support (the plugin has no table).

## Screenshots

![Quick order preview](docs/images/shop-quick-order-preview.jpg)

![Quick order result](docs/images/shop-quick-order-result.jpg)

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ (8.3+ with Sylius 2.3) |
| Sylius | 2.2.9+, 2.3 |
| Symfony | 6.4, 7.4 (Sylius 2.2); 6.4, 7.4, 8.0 (Sylius 2.3) |

Sylius 2.2.9 is the minimum because earlier 2.2 versions are affected by cart and payment
security advisories (GHSA-2rv4-pjmm-7fxf, GHSA-vv4h-q2x8-74g4).

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-quick-order-plugin
    ```

2. Register the bundle in `config/bundles.php`:

    ```php
    Tmonier\SyliusQuickOrderPlugin\TmonierSyliusQuickOrderPlugin::class => ['all' => true],
    ```

3. Import the configuration in `config/packages/tmonier_sylius_quick_order.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusQuickOrderPlugin/config/config.yaml" }
    ```

4. Import the routes in `config/routes/tmonier_sylius_quick_order.yaml`:

    ```yaml
    tmonier_sylius_quick_order:
        resource: "@TmonierSyliusQuickOrderPlugin/config/routes.yaml"
    ```

5. Clear the cache. There is no migration: the plugin has no table.

The API operation is registered automatically when API Platform is enabled.

## Configuration

```yaml
# config/packages/tmonier_sylius_quick_order.yaml
tmonier_sylius_quick_order:
    max_lines: 200              # per paste, CSV file, reorder or API call
    max_total_quantity: 20000   # units per paste, CSV file, reorder or API call
    max_file_size: 1048576      # bytes, CSV file and pasted text
    expose_available_stock: false  # true: "insufficient stock" lines tell how many units are left
    default_mode: partial       # or all_or_nothing
    allow_guests: true          # false: visitors are sent to the login page
    quantity_rounding: reject   # or round_up (see "Pack sizes" below)
```

Quantities are whole numbers (`1000`); a zero fraction of one or two digits, as spreadsheets
export it, is accepted (`5.0`, `12,00`). Thousands groups (`1,000`, `1.000`, `1 000`) are
reported as *ambiguous* rather than guessed, since separators mean different things in
different countries; other fractions (`2.5`) are invalid. In a pasted line, cells after the
quantity (descriptions, prices) are ignored, except `CODE,1,000`-like lines with commas (a
1-3 digit quantity followed by exactly 3 digits), refused as ambiguous: use `;` or a tab.
In a CSV file, every row needs a quantity (an empty cell is an error, while a pasted line
without quantity means 1), and a row with more cells than the header whose quantity is
followed by 3 digits is refused as ambiguous.

What visitors see is limited on purpose: stock levels are not shown (unless
`expose_available_stock`), product names only appear for products sold in the channel, and
for visitors without an account (carts without a shop user) unknown, disabled, unpriced and
other-channel codes all read *not available*, so the page cannot be used to discover
unpublished products. Logged-in customers get the detailed status.

The previewed lines stay in the session (the last 5 previews, so several tabs work); each
"add" form only carries a one-time token, so what is added is exactly what was previewed
(and checked again). Adding the same preview twice (back button) shows the page again with
the pasted text.

The maximum quantity of a cart line is the Sylius parameter
`sylius.order_item_quantity_modifier.limit` (9999 by default). Sylius silently caps a line
above it; the quick order reports it instead.

## Shop API

```http
POST /api/v2/shop/orders/{tokenValue}/bulk-items
Content-Type: application/ld+json
Authorization: Bearer <shop user token, for a customer cart>

{
  "mode": "partial",
  "lines": [
    {"code": "QO-TEE-S-RED", "quantity": 2},
    {"code": "QO-TEE-M-BLUE", "quantity": 10}
  ]
}
```

Response (`200`):

```json
{
  "cart": "/api/v2/shop/orders/abc123",
  "applied": true,
  "mode": "partial",
  "addedCount": 2,
  "refusal": null,
  "lines": [
    {"lineNumbers": [1], "code": "QO-TEE-S-RED", "requestedQuantity": "2", "quantity": 2, "addedQuantity": 2, "status": "added", "available": null}
  ]
}
```

- Cart access follows the core `AddItemToCart` rule: a cart created by a registered customer
  is only reachable with that customer's token (`404` otherwise); guest carts are reachable
  by token.
- `422` when the request is refused as a whole, with the reason in `detail`: invalid `mode`,
  more than `max_lines` lines (`too_many_lines`), more than `max_total_quantity` units
  (`too_many_units`), no line (`empty`), locked cart (`cart_locked`), or a refusal of a
  `tmonier_quick_order.pre_bulk_add` listener (its reason).
- Line-level problems never fail the request: read `status` (same values as the shop:
  `added`, `adjusted`, `removed`, `skipped`, `not_available`, `unknown_code`, `disabled`,
  `not_in_channel`, `no_channel_pricing`, `insufficient_stock`, `quantity_limit_exceeded`,
  `quantity_constraint`, `invalid_quantity`, `invalid_line`). `quantity` must be a JSON
  integer or a string of digits, up to 999999999. `available` is `null` unless
  `expose_available_stock` is on. In `all_or_nothing` mode, `applied: false` means nothing
  was added.
- Line numbers are the 1-based positions in `lines` (merged duplicates list several numbers).

## Extension points

| What | How |
|---|---|
| Refuse carts that must not change (approval in progress, punch-out session...) | Implement `Tmonier\SyliusQuickOrderPlugin\Cart\LockedCartCheckerInterface` and tag the service `tmonier_quick_order.locked_cart_checker`. |
| Pack sizes, minimum and maximum quantities | Alias `tmonier_quick_order.quantity_constraint_provider` to your `QuantityConstraintProviderInterface` implementation returning a `QuantityConstraint(minimum, multipleOf, maximum)`. The constraint applies to the quantity the cart line will hold (already in the cart + added). With `quantity_rounding: round_up`, the added quantity is raised so that this total is valid (shown in the preview); with `reject`, the line is reported. The default provider returns no constraint. A future `tmonier/sylius-container-fill-plugin` will own this logistics data. |
| Your own rules before adding (budget, credit...) | Listen to `tmonier_quick_order.pre_bulk_add` (`PreBulkAddEvent`: cart, validated lines, mode) and call `refuse('my_reason')`; translate `tmonier_quick_order.refusal.my_reason` in the `flashes` domain. The API answers `422` with the reason. |
| React after the addition | `tmonier_quick_order.post_bulk_add` (`PostBulkAddEvent`: cart, result), dispatched after the flush. |
| Use the engine elsewhere | `Tmonier\SyliusQuickOrderPlugin\Adder\BulkCartAdderInterface` (`validate()` for a preview, `add()`), with `PasteParser`, `CsvParser` or `LineCollector` to build the input. |

Shop parts use [Twig Hooks](https://stack.sylius.com/twig-hooks/getting-started):

| Hook / grid | Content |
|---|---|
| `sylius_shop.account.order.show.content.main.header.buttons` → `tmonier_quick_order_reorder` | "Order again" button on the order page |
| grid `sylius_shop_account_order`, item action `tmonier_quick_order_reorder` | "Order again" in the order list |
| `sylius_shop.cart.index.content` → `tmonier_quick_order_link` | Link to the quick order page under the cart |
| menu `sylius.menu.shop.account` → `tmonier_quick_order` | Link in the customer account menu |

Templates can be overridden in `templates/bundles/TmonierSyliusQuickOrderPlugin/`.

### Sylius core events

The plugin does **not** dispatch `sylius.cart_item_add` per line: the core listener of this
event calls `OrderModifier::addToOrder()`, which would add every line a second time and
process the order once per line. Listeners of the core cart events therefore do not see
quick order additions; listen to `tmonier_quick_order.post_bulk_add` instead.

### Request for quote plugin

With `tmonier/sylius-quote-request-plugin` installed, the cart of an accepted quote (locked
lines and prices) is refused automatically: the plugin registers a bridge to that plugin's
`tmonier_quote_request.quote_cart_checker` service when it exists, without depending on it.

## Limitations

- Codes are product **variant** codes, up to 255 characters. Matching is the same on MySQL,
  MariaDB and PostgreSQL: the exact code first, otherwise a case-insensitive match when it
  designates a single variant (that second query cannot use the code index; it only runs
  for codes without exact match). Lines resolved to the same variant are merged.
- No XLSX import: paste from the spreadsheet or save it as CSV.
- No variant matrix on product pages yet (planned), no saved lists, no customer-specific
  prices: prices are those of the channel, as in the regular cart.
- The cart is not locked while lines are added: two simultaneous changes of the same cart
  follow the core rule (the last write wins).
- Reorder adds today's prices and promotions, not those of the past order.

## Development

```bash
cp compose.override.dist.yml compose.override.yml   # publishes MySQL on 3306 (change ports at will)
docker compose up -d mysql mailhog
composer install
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n   # includes the quick order demo data

vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:migrations:migrate -n
APP_ENV=test vendor/bin/console sylius:fixtures:load -n
APP_ENV=test vendor/bin/phpunit --testsuite non-unit
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

The demo data contains a T-shirt with 12 variants `QO-TEE-<S|M|L|XL>-<RED|BLUE|BLACK>`
(`QO-TEE-XL-BLACK` out of stock, `QO-TEE-XL-BLUE` disabled, `QO-TEE-XL-RED` without price),
a buyer `quickorder@example.com` / `sylius` with two past orders to reorder, and
[`docs/examples/quick-order-demo.csv`](docs/examples/quick-order-demo.csv) to upload.
The demo channel requires account verification and the Sylius `shop_user` fixture does not
verify accounts: verify the buyer before logging in, for instance with
`UPDATE sylius_shop_user SET verified_at = NOW() WHERE username = 'quickorder@example.com';`.

## License

MIT. See [LICENSE](LICENSE).
