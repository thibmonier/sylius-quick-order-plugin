# Quick order plugin: specification and decisions

Scope: v1 of `tmonier/sylius-quick-order-plugin` (quick order page, CSV, reorder, shop API).
The variant matrix on product pages is v1.1 and not part of this version.

## 1. Research summary

- B2B buyers order by reference, often from a spreadsheet or an ERP export. The usual
  storefront answer is a quick order form with paste and CSV upload, a preview with per-line
  errors, and a reorder action. Adobe Commerce's quick order CSV upload caps a file at 200
  lines (https://experienceleague.adobe.com/developer/commerce/storefront/dropins-b2b/quick-order/containers/quick-order-csv-upload):
  we use the same default limit and the same `SKU,QTY` header.
- Sylius 2 core: adding to the cart goes through `OrderModifier::addToOrder()`, which calls
  the order processor on every call (O(N²) for N lines with promotions, taxes, shipping).
  No bulk add exists in the shop, the API (`AddItemToCart`, one line per request) or the
  admin.

## 2. Verified seams (Sylius 2.3.0 sources in vendor, 2.2 branch checked on GitHub)

| Seam | Finding | Use |
|---|---|---|
| `OrderModifier::addToOrder()` (`Component/Order/Modifier/OrderModifier.php`) | merges with `equals()` + `OrderItemQuantityModifier`, then `orderProcessor->process()` per call | reimplemented merge, one `process()` |
| `OrderItem::equals()` (Core) | same variant ⇒ equal (immutable items included) | merge rule; quote carts are refused before (see 5) |
| `LimitingOrderItemQuantityModifier` decorates `sylius.modifier.order_item_quantity` with `%sylius.order_item_quantity_modifier.limit%` = 9999 | caps silently | checked before adding, reported as `quantity_limit_exceeded` |
| `ProductVariantPriceCalculator::calculate()` | throws `MissingChannelConfigurationException` without channel pricing, which would fail the whole processing | lines without price are refused before (`no_channel_pricing`) |
| `AvailabilityChecker::isStockSufficient()` | `!tracked || qty <= onHand - onHold` | called with quantity already in the cart + requested |
| API `AddingEligibleProductVariantToCartValidator` | product enabled, variant enabled, stock (with cart quantity), product in channel: constraints of the command, not the handler | same checks in `BulkCartAdder` |
| API `AddItemToCartHandler::assertCartAccessible()` | private in a final class | rule copied in `BulkAddItemsProcessor::isCartAccessible()` |
| `sylius_shop.listener.cart_item_add` listens to `sylius.cart_item_add` and calls `addToOrder()` | re-dispatching the core event per line would add each line twice and bring O(N²) back | not dispatched; plugin events instead |
| `CartItemAdder` and `CART_ITEM_POST_ADD` | exist in 2.3 only | not used in v1 (side effects of a per-line POST_ADD not verified) |
| Shop account order grid `sylius_shop_account_order` (YAML `grids/account/order.yml` and `Grid/Account/OrderGrid.php`) | item actions, no hook inside grid rows | grid action `tmonier_quick_order_reorder` (YAML merge verified by a functional test) |
| Hook `sylius_shop.account.order.show.content.main.header.buttons` (2.2 and 2.3) | holds the "Pay" button | "Order again" button |
| Hook `sylius_shop.cart.index.content` | outside the cart live component, rendered for empty carts too | quick order link |
| `ShopBasedCartContext` | caches the new cart in a property, no `kernel.reset` | test-only listener resets it between requests of one kernel |
| Service ids on 2.2 (`sylius.checker.inventory.availability`, `sylius.factory.cart_item`, `sylius.modifier.order_item_quantity`, `sylius.order_processing.order_processor`, `sylius_api.context.user.token_based`, `sylius.context.cart.new_shop_based`) | present in the 2.2 XML configuration | used as is |

## 3. Design

- `Input`: `PasteParser`, `CsvParser` (`fgetcsv`, BOM, separator detection, header detection,
  Windows-1252 fallback, 1 MB / 200 lines limits, NUL bytes rejected) and `LineCollector`
  (validation, case-insensitive merge of duplicates, line numbers kept). `QuickOrderInput`
  travels from the preview to the "add" step as JSON in a hidden field and is re-validated
  (it is as untrusted as the original input).
- `Adder/BulkCartAdder`:
  1. refuse rejected inputs and locked carts (`LockedCartCheckerInterface`, tagged);
  2. load all variants in one DQL query (product, product channels, channel pricings
     fetch-joined); exact code match, then case-insensitive among the returned rows;
  3. check each line (enabled, channel by code, price, quantity constraint, 9999 limit,
     stock including the cart and earlier lines of the same batch);
  4. `pre_bulk_add` event (may refuse); all-or-nothing stops here if a line is invalid;
  5. create items, merge with `equals()` + quantity modifier, **one** `process()`;
  6. compare per-variant quantities before / after processing: `added`, `adjusted`
     (a processor kept fewer units) or `removed`; in all-or-nothing mode any discrepancy
     reverts the changes (second processing, nothing flushed);
  7. **one** flush, `post_bulk_add` event.
- Shop controller without JavaScript: index, preview (POST), add (POST, PRG to the cart or
  to the result page), result, CSV template, CSV report (last result kept in the session),
  reorder (POST). CSRF on every POST.
- API Platform: `BulkAddItemsToCart` resource with one `Post` operation (`read: false`,
  dedicated processor, non-resource output DTO `BulkAddItemsResult`), registered through
  `api_platform.mapping.paths` only when API Platform is installed (attributes valid on
  API Platform 4.x and 5.x).
- Reorder: past order items become quick order lines; items whose variant is gone become
  `unknown_code` lines labelled with the product name.

## 4. Decisions

| Decision | Why |
|---|---|
| No entity, no migration | The quick order is a transient input; saved lists are out of scope (the official wishlist plugin covers them). |
| Statuses after `process()` | Other processors (quote price lock, custom rules) may remove or change items: a status computed before processing could lie. |
| Channel and variant compared by code | A cart channel may be another instance than the product's (cleared entity manager in workers and tests). |
| Partial mode by default, all-or-nothing on demand | Buyers want what can be added; integrations sometimes need atomicity. |
| Line errors never fail the API request (200 + statuses), whole-input refusals are 422 | A client needs the per-line report in both cases. |
| `QuantityConstraintProviderInterface` with a null default | Logistics data (units per case, multiples) will belong to `tmonier/sylius-container-fill-plugin`; quick order only consumes it. |
| Quote carts refused, not diverted | `QuoteOrderSynchronizer` rewrites quote carts and `equals()` would merge into locked lines; diverting to a second cart would need a cart switch UI. Refusal message tells the buyer to release or finish the quote first. |
| Duck-typed bridge to the quote plugin | No dependency in either direction; registered by a compiler pass when `tmonier_quote_request.quote_cart_checker` exists. |
| No XLSX | TSV paste covers spreadsheets; no `phpoffice/phpspreadsheet` dependency. |
| No `sylius.cart_item_add` per line | See section 2 (double insertion, O(N²)). |
| CSV cells starting with `= + - @` TAB CR prefixed with `'` | CSV injection in the downloadable report. |
| `^2.2.9 \|\| ^2.3` | Cart / payment advisories GHSA-2rv4-pjmm-7fxf and GHSA-vv4h-q2x8-74g4 fixed in 2.2.9. |

## 5. Cross-plugin constraints

- Quote request plugin: quote carts refused (bridge above); statuses computed after
  processing, so the quote price lock processor (priority 45) is taken into account.
- Container fill plugin (future): implements the quantity constraint provider.

## 6. Tests

- Unit: parsers (separators, headers, encodings, limits), payload tampering, quantity
  constraints, report escaping, bulk adder (200 lines = 1 processing and 1 flush, merge,
  every status, constraint reject / round up, all-or-nothing with and without revert,
  locked cart, events), bridge pass, configuration, translations (7 locales, identical keys,
  every used key exists).
- Integration (real services, rolled-back transaction): 200 lines with a counting wrapper
  around the real order processor (1 call); bulk vs one-by-one totals equal with a
  `cart_quantity` percentage promotion and an `item_total` fixed promotion; the 12-variant
  T-shirt (out of stock, disabled, no price).
- Functional: shop preview / add / CSV / report / all-or-nothing / refusals / quote cart,
  reorder from the list and the order page, ownership, API (guest cart, owner token, other
  customer, locked cart, limits, OpenAPI documentation).

## 7. Not verified / open

- CI on Sylius 2.2 / API Platform 4 / Symfony 6.4 and PostgreSQL / MariaDB: written for the
  matrix, run locally only on Sylius 2.3.0, Symfony 7.4, API Platform 5.0, MySQL 8.4.
- Dispatching `CART_ITEM_POST_ADD` per line on 2.3 (possible through version detection;
  side effects not verified) is left out.
- No locking: concurrent changes of one cart follow the core "last write wins".
