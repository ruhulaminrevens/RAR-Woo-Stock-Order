# Changelog

## 1.3.0 — Unreleased

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
