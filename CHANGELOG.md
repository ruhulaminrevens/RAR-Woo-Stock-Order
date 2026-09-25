# Changelog

## 1.3.1 — 2026-09-25

### Critical fix
- The staff app stopped at startup in v1.3.0 because of a JavaScript error (`$(...).forEach is not a function`). As a result:
  - the dashboard stayed on "Loading…";
  - stock Save/Quick did nothing;
  - product search and order creation did not work;
  - manager order lists and status changes did not work.

  Fixed, and CI now has a guard for this error.

### Fixed
- Manager dashboard and order lists failed on stores that have WooCommerce refunds, because refund records were read as orders. Every query is now limited to real orders.
- Checkout-block drafts (abandoned carts) were counted as orders and sales. They could also be chosen as a status, and WooCommerce deletes draft orders automatically. Drafts are now excluded everywhere.
- Sales totals now leave out Pending payment orders, as WooCommerce Analytics does.
- Refunds can no longer be made from the quick status control, because Undo could not reverse them. Refunds stay in the WooCommerce order screen.
- Stock Set, Quick adjust and the ±buttons now use WooCommerce's atomic stock update, so a checkout that happens at the same time is never overwritten.
- District validation uses WooCommerce state codes, so orders keep working on Bangla-translated sites.
- The manager "Today" order list showed nothing between 6 pm and midnight because of a timezone error. Fixed.
- `/staff/` works right after activation, including activation by WP-CLI or a host installer.
- Turning off "Enable staff app" now blocks the app's API calls too.
- Rejected orders no longer leave empty WooCommerce orders behind: items are checked before the order is created.
- Product names with `&` no longer show `&amp;`.
- The sales slip grows to fit long orders, so the totals and footer no longer overlap.
- Expired sessions show a clear "sign in again" message instead of retrying forever.

### Improved
- The live clock uses the store timezone offset in the owner's format: `Friday । Sep 25, 2026 । 01:49:02 pm`.
- Create Order shows the discount amount in taka when a percentage discount is used.
- Price overrides and discounts are written to a private order note (regular price, charged price, discount, staff name).
- The dashboard is cached for 60 seconds and refreshed right away after stock or order changes made in the app. Order reports read orders page by page.
- The runtime tests now also cover drafts, refunds, refused refunds, translated districts and the disabled switch.

## 1.3.0 — 2026-09-25

### Finalization — Stock / Order / Manager workflows
- Added a Stock Manager quick-adjust modal with -1, +1, +5 and +10 controls plus exact quantity save.
- Added per-product Movement Log with user, timestamp, previous quantity, new quantity and delta; history is capped to the most recent 60 manual changes.
- Preserved WooCommerce as the inventory source of truth and continued using WooCommerce CRUD for stock writes.
- Added strict +88-prefixed Bangladesh phone UI with an 11-digit local mobile input and existing server-side normalization.
- Preserved automatic order date and WooCommerce-generated Order No on save.
- Preserved searchable District and dependent Town / City / Upazila selectors.
- Preserved color-coded product search, serial numbers, out-of-stock blocking, fixed/% discount, shipping, total and amount in words.
- Preserved PNG Sales Order Slip generation and native file Share Sheet support for WhatsApp, Messenger and other installed mobile apps, with download fallback.
- Added Shop Manager status Undo using a server-issued 5-minute token with stale-change protection.
- Added manager Top Products, Payment Mix and Sales Channels for the selected 7/30/90-day reporting period.
- Expanded runtime tests for quick stock adjustments, Movement Log, strict phone UI, status Undo, manager breakdowns and share integration.
- Bumped plugin metadata and package validation to v1.3.0.

### Checkpoint 1 — Dashboard/API foundation
- Added a dedicated read-only reporting service for dashboard metrics, inventory health, recent orders, attention items and manager summaries.
- Added period-aware dashboard APIs for Today, 7 days and This month.
- Added manager reporting periods for 7, 30 and 90 days.
- Added role-aware dashboard payloads so Staff and Shop Manager receive only the data/actions intended for their role.
- Added managed-stock semantics: Available / Live counts only positive managed quantities; unmanaged stock remains separately visible as Not tracked.
- Added units-in-hand and estimated stock-value metrics from WooCommerce product lookup data.
- Added manager Order Control counts for All Orders, Live Orders and Processing.
- Added manager Processing order drilldown.
- Added recent-order and Needs Attention dashboard feeds.
- Added 7-day sales/order trend data for lightweight charts.
- Redesigned the dashboard into a responsive dark operations workspace inspired by the approved prototype while preserving the existing Stock Manager and Create Order workflows.
- Added Staff-only simplified dashboard and Shop Manager-only Order Control / Sales & Growth sections.
- Fixed manager dashboard action binding so every `data-orders-mode` button is interactive.
- Preserved the v1.2.1 custom WooCommerce status normalization and resilient dashboard error handling.
- Expanded runtime tests for Staff/Manager role separation, period metrics, manager Processing drilldown and the new dashboard payload.

## 1.2.1 — 2026-09-25

### Dashboard / Manager Fixes
- Normalized registered WooCommerce order-status slugs before every dashboard, analytics and manager order query.
- Fixed live stores with custom statuses such as Confirmed / Order Confirmed / Returned causing dashboard data to remain unloaded.
- Dashboard inventory values now remain available even if a separate order-metric query fails.
- Added automatic dashboard retry, 60-second refresh and refresh-on-resume behavior.
- Added clear manager analytics empty / warning states.

### Sales Order Slip
- Added a dedicated IMAGE column to the PNG Sales Order Slip.
- Slip table is now: SL | IMAGE | ITEM | QTY | RATE | AMOUNT.
- Product thumbnail is preserved when an item is added and rendered with an image-safe fallback.

## 1.2.0 — 2026-09-25

### Dashboard
- Rebuilt dashboard with a live date/time bar and colorful information cards.
- Added Today's Orders, Today's Sales, Completed Orders and Returned/Cancelled metrics.
- Added clickable All Stock, Available/Live Stock and Out of Stock cards.
- Added fixed inventory health bands: 11+ healthy, 1–10 low, 0/out-of-stock, and unmanaged.
- Added Shop Manager-only 7-day sales chart, week total and growth vs previous week.

### Stock Manager
- Added inventory filter chips for All, Healthy, Low, Out and Unmanaged.
- Added color-highlighted stock cards.
- Added load-more inventory navigation.
- Kept WooCommerce CRUD stock updates and stock-change logging.

### Create Order
- Fixed raw BDT currency entity rendering.
- Added automatic order date and generated order-number display.
- Added Bangladesh mobile validation/normalization.
- Added searchable 64-district selector.
- Added dependent searchable Town/City/Upazila selector using bundled Bangladesh location data.
- Added server-side district/city validation.
- Added stock-color product search.
- Blocked out-of-stock products in UI and server validation.
- Added serial number to order items.
- Added fixed / percentage discount.
- Added total in words.
- Added numeric order totals in API responses.

### Save & Share
- Added PNG sales-order slip generation in the staff app.
- Added native Web Share integration for supported mobile browsers.
- Added automatic PNG download fallback when native file sharing is unavailable.

### Manager Control Center
- Added All Orders view.
- Added Live Orders view excluding completed/cancelled/refunded/failed/returned orders.
- Added order status updates using registered WooCommerce/custom statuses.
- Added customer, contact, address and order-item summaries.

### Security / Reliability
- Preserved HPOS compatibility and capability-based access.
- Preserved duplicate-order retry protection.
- Added BDT Unicode regression coverage.
- Added Bangladesh address-map validation.
- Added manager/staff UI separation tests.
- Added discount, out-of-stock and manager-order runtime tests.

## 1.1.0 — 2026-09-25

- Removed duplicate product add/delete management from the staff PWA.
- Fixed currency rendering, search and unmanaged stock UX.
- Added Bangladesh district validation and stock-limit checks.
- Added duplicate-order retry protection.
- Hardened authenticated PWA caching.
- Added runtime smoke tests and canonical release packaging.

## 1.0.0 — 2026-09-24

- Initial mobile-first Stock Manager and Staff Order Entry PWA release.
