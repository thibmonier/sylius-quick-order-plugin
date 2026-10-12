# Quick order plugin: specification and decisions

Scope: v1.0 of `tmonier/sylius-quick-order-plugin` (quick order page, CSV, reorder, shop API)
and v1.1 (order matrix on product pages, section 8).

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

### Review round (2026-10-12)

| Decision | Why |
|---|---|
| Quantities are plain integers; `1,000`, `1.000`, `1 000`, `12.0` are `invalid_quantity` | Separators are ambiguous across locales; truncating `1.000` to 1 lost 999 units silently. |
| A CSV header row whose code or quantity column is not recognised refuses the file (`csv_columns`); header cells are read as UTF-8 or Windows-1252; CR-only line endings normalised | Never "1 of everything" because a header has an unexpected name or encoding. |
| `max_total_quantity` (10000) per request, quantities up to 999999999, overflow-safe rounding | One request could create millions of order item units; huge API integers caused a 500. |
| Pasted text limited to `max_file_size` and read line by line, stopping past `max_lines` | Unbounded paste allocated millions of strings. |
| The previewed input stays in the session (one per session, one-time nonce in the form) | The client-posted payload could carry thousands of line numbers and inflated sessions. |
| Stock levels hidden unless `expose_available_stock`; product names only for products sold in the channel; for carts without shop user, unknown / disabled / other-channel / unpriced codes are all `not_available` | The core never discloses stock counts; the preview must not reveal unpublished products. |
| Quantity constraints checked on cart quantity + added quantity, rounding on that total | Constraints are about the cart line, not the increment. |
| Exact code match, then case-insensitive only when unique, on every database; lines merged by resolved variant | Same behaviour on MySQL and PostgreSQL; distinct `abc` / `ABC` variants are never collapsed. |
| Translations batched (two queries); cart quantities keyed by variant id | Constant number of queries whatever the number of lines (integration test). |
| `pre_bulk_add` refusals are 422 in the API | Whole-request refusals are client errors. |

### Re-verification round (product decisions, 2026-10-12)

| Decision | Why |
|---|---|
| Zero fractions of 1-2 digits accepted (`5.0`, `12,00`); thousands groups `ambiguous_quantity`; other fractions `invalid_quantity` | Spreadsheet exports must work; only ambiguous forms are refused. |
| Paste with commas: extra cells ignored, except quantity 1-3 digits followed by exactly 3 digits (ambiguous) | `ABC-1,2,100` (code, qty, price) must not become `2,100`. |
| CSV: header found on the first non-blank row; unknown names mean positional columns; refusal only when a named code column has no quantity column; empty quantity cell is an error; extra 3-digit cell after the quantity is ambiguous | Harmless headers (`Product;Quantity`, `EAN;Units`) must not refuse files; nothing defaults to 1 in files. |
| Last 5 previews kept in the session, one-time nonces; a used nonce re-renders the page with the pasted text | Several tabs, back button. |
| `max_total_quantity` 20000, message gives the limit | Large but bounded B2B orders. |
| Masked (`not_available`) lines carry no names | Indistinguishable from unknown codes. |

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
- Integration: the number of queries of `validate()` is the same for 10 and 50 lines.
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

## 8. v1.1: order matrix

Sources: memo B2B section 3.1 "v1.1 : matrice" (docs/2026-10-11-memo-b2b.md), memo container
fill section 4 (the matrix embeds other plugins' gauges and must emit `cart_changed`).

### Verified seams (Sylius 2.3.0 vendor)

| Seam | Finding | Use |
|---|---|---|
| Hook `sylius_shop.product.show.content.info.summary`: `add_to_cart` (component `sylius_shop:product:add_to_cart_form`, priority 100) | Its template wraps everything in the core `<form>`: anything in `...summary.add_to_cart` would be a nested form | Matrix = sibling hookable (priority 90) |
| Twig hooks config merges props of a hookable defined twice | Our `add_to_cart.props.template` replaces the template only (component and `product` prop kept; checked with `debug:config`) | Wrapper template renders an empty root for matrix products, the core template otherwise |
| Hookable `condition` (expression) | Exists in twig-hooks 0.14 (Sylius 2.3) but not in 0.9, required by Sylius 2.2 (checked on GitHub: no `HookableConditionChecker` in v0.9.x) | Wrapper template instead |
| Hook names in hookable templates are auto-prefixed with the parent hookable (`HooksRuntime::renderHook`) | `{% hook 'a.b' %}` inside the matrix would become `sylius_shop...tmonier_quick_order_matrix.a.b` | Our extension hooks pass `_prefixes: []`: their names are absolute |
| `CatalogPricesCalculatorInterface` (2.3, `sylius.calculator.product_variant_catalog_price`) | UPGRADE-2.3 item 4: catalog display prices, decorable independently of cart prices | `CatalogPriceCalculatorPass` aliases it, else `sylius.calculator.product_variant_price` (2.2) |
| `calculateLowestPriceBeforeDiscount()` | Null unless the channel shows the Omnibus lowest price for this variant | Struck price when greater than the price |
| `sylius:shop:cart_changed` (`Cart\FormComponent::SYLIUS_SHOP_CART_CHANGED`), listened to by `Cart\WidgetComponent` and `SummaryComponent` | Emitted by components | `MatrixOrderComponent::add()` emits it after an applied addition |
| Live component tag `sylius.live_component.shop` (`LiveComponentTagPass`) | Adds the twig.component tag with the shop live route | Same tag, key `tmonier_quick_order:product:matrix` |
| UX Live: writable array prop `quantities` | The browser refuses to set a model path that does not exist (`Invalid model name`) | Every orderable cell key exists from `PostMount`; keys are `v<id>` so the array is never a JSON list |
| UX Live: a public getter named like a LiveProp | The dehydrator reads the getter (`getResult()` returned an object for the `result` prop) | Prop `lastResult`, getter `getResult()` |

### Decisions

| Decision | Why |
|---|---|
| Activation: excluded list, forced list, checkbox attribute, then "1-2 options and <= max_variants enabled variants" | No field on Product; the attribute gives merchants a switch per product from the admin. |
| Grid only for 2 options whose used values fit `max_grid_cells` and whose variants map one-to-one to cells; otherwise a list | A grid must never hide or merge variants. |
| Disabled variants greyed in a grid (shape kept), left out of a list; no price = "not available"; tracked stock with nothing available = "out of stock" | Same information as the core page, never a stock count. Untracked stock is orderable (no backorders in the core). |
| Adding = `MatrixOrderHandler` → v1 `BulkCartAdder` | One processing, the v1 statuses and checks (enabled, channel, price, stock with the cart, quantity rules, unit limit, quote carts); only variants of the product, array bounded by the number of variants. |
| Stay on the product page after adding, result above the grid, added cells cleared, failing cells keep their value with the message | B2B buyers fill several products in a row; the cart widget refreshes through `cart_changed`. |
| Estimate = catalog price x whole quantities, labelled "before cart promotions and shipping" | Tier prices (tier-pricing plugin) and promotions are applied by the cart, which stays the truth. |
| No-JS fallback: same form posted to `tmonier_quick_order_shop_matrix_add` (CSRF), redirect to the cart or the quick order result page | Progressive enhancement: `data-action="live#action:prevent"` on the same form. |
| Accessibility: `<table>` with `<caption>`, `th scope=col/row`, one labelled number input per cell (`aria-label` "row / column"), errors linked with `aria-describedby`, `aria-live` estimate | Advanced keyboard navigation (arrows between cells) is v1.2. |

### Tests

- Unit: activation rules (lists, attribute, automatic rule, disabled variants), builder (grid,
  statuses, unused values, list fallbacks, struck price, quantity rules), order handler
  (foreign variants ignored, bounded array, zeros, ambiguous quantities, mode).
- Functional: matrix product page (grid, headers, labels, unavailable cells, no core form, no
  stock count), other products keep the core form, no-JS fallback (cart, result page, 404 for
  other products, CSRF), live component (`InteractsWithLiveComponents`: add, emitted
  `sylius:shop:cart_changed`, tampered foreign variant ignored, all-or-nothing errors without
  emission).

### Not verified

- Sylius 2.2 (UX Live 2.x, twig-hooks 0.9) and the tier pricing plugin installed together:
  written against their APIs, run on Sylius 2.3 / UX 3.5 only.

### Review round (2026-10-12)

| Decision | Why |
|---|---|
| Struck price = original price when greater (core `sylius_has_discount`); Omnibus lowest price as the core "lowest price ... was" line, only for discounted variants | Same prices as the core block above the matrix. |
| No-JS outcomes come back to the product page (result kept once in the session, failed quantities refilled); a complete success goes to the cart; `result` and `report` quick order pages honour `allow_guests` | A product page user never lands on the paste/CSV pages. |
| Empty submit: "Enter a quantity for at least one variant." | The quick order message talks about pasting and files. |
| Text inputs with `inputmode=numeric`; the estimate uses `LineCollector::parseQuantity()`, the cell's quantity rule with the cart content and rounding, and the cart line limit | The browser empties unreadable numbers; the estimate must match what the cart takes. |
| Checkbox without `value` | UX Live sends a boolean only then; with a value it could not be unchecked. |
| Keys for cells that become orderable after mount (`PreReRender`); a changed quantity drops its old message (`onUpdated`) | Model paths must exist; messages must not quote old values. |
| Grid limit counts the option values the product uses | Option values are shared across products. |
| Empty matrix: "Out of stock" only when stock is the reason, else "Not available" | Unpriced or disabled products are not a stock problem. |
| Accessibility: hidden text for unavailable cells, `aria-invalid`, pack hint and error in `aria-describedby`, failed cells listed as links, explained "all or nothing" | Screen reader and keyboard users get the same information. |
| Tier price table: absolute hook `tmonier_quick_order.shop.product.add_to_cart` (context `product`, `form`) rendered by the hidden core form; when the tier pricing bundle is installed, our template there compares the tiers of every enabled variant (`tmonier_tier_pricing_table()`): same tiers → the tier plugin's table, different tiers → a note "volume prices depend on the variant, the cart applies them" | The table no longer disappears and never shows one variant's tiers as if they were the product's; no change in the tier pricing plugin. |
| Stale messages: snapshot `resultQuantities` compared in `PreReRender` | The browser updates `quantities.v<id>`: the `onUpdated` hook of the whole prop is never called for nested paths (`LiveComponentHydrator::processOnUpdatedHook`). |
| Estimate: same input types and cleaning as the cart (`LineCollector::clean()`) | `'""'`, a no-break space or a BOM alone were estimated as 1 unit. |

