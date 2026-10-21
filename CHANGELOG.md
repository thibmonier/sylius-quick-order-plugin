# Changelog

## Unreleased

## 1.2.1 (2026-10-21)

- Fix: the rate limiter is typed `RateLimiterFactoryInterface` instead of the concrete `RateLimiterFactory`, which Symfony 8
  no longer autowires (compatible with Symfony 7.4 and 8.x).

## 1.2.0 (2026-10-18)

- `example_lines` option: the example shown as the placeholder of the paste box is configurable; the default is now a
  translated, neutral example (`tmonier_quick_order.ui.lines_placeholder`) instead of a hard-coded one.

## 1.1.0 (2026-10-12)

- Order matrix on product pages: live component `tmonier_quick_order:product:matrix` in
  `sylius_shop.product.show.content.info.summary`, replacing the core add to cart form on
  products with 1 or 2 options (grid for 2 options, list for 1, beyond 2 options or beyond
  `matrix.max_grid_cells` cells). Activation without a field on Product: configuration lists,
  optional checkbox attribute, automatic rule (`matrix.auto`, `matrix.max_variants`).
- Catalog prices (`CatalogPricesCalculatorInterface` on Sylius 2.3, variant price calculator
  on 2.2), Omnibus struck price, estimate of units and amount.
- Adding uses the bulk cart adder (one order processing, line statuses, all-or-nothing option)
  and emits `sylius:shop:cart_changed`; quantities are re-checked on the server (variants of the
  product only, bounded array). No-JavaScript fallback: `POST /{locale}/quick-order/products/{productId}/matrix`.
- Hook `tmonier_quick_order.shop.product.matrix` under the matrix for other plugins.
- New configuration node `matrix`. No change to the 1.0 public API.
- Hook `tmonier_quick_order.shop.product.add_to_cart` (context `product`, `form`) for matrix
  products; when tmonier/sylius-tier-pricing-plugin is installed, its tier price table is shown
  there if every variant has the same tiers, otherwise a note.
- The quick order result and report pages honour `allow_guests`.

## 1.0.0

- Quick order page (paste, CSV), preview, error report, reorder, shop API bulk add.
