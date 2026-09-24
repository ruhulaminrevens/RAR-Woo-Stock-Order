# Changelog

## 1.1.0 — 2026-09-25

### Focused staff workflow
- Removed staff product creation/deletion from the PWA and settings.
- Product lifecycle management now stays in native WooCommerce for Administrator / Shop Manager users.
- Added upgrade migration to remove legacy RAR WSO add/delete capabilities and old settings.

### Stock Manager
- Fixed malformed WooCommerce currency/HTML output.
- Reworked product search to use WooCommerce product data-store search.
- Added stale-response protection for rapid product searches.
- Fixed unmanaged-stock UX so an unmanaged product is not presented as quantity zero.
- Added explicit stock-management activation through the stock update flow.
- Preserved WooCommerce CRUD writes and stock-change logging.

### Create Order
- Added strict Bangladesh district validation on client and server.
- Added available-stock validation before order creation when backorders are disabled.
- Added duplicate-order retry/idempotency protection for network/retry scenarios.
- Added safer staff session-expiry handling.
- Hid WooCommerce admin order links from users without WooCommerce management permission.
- Added extension hooks for shipping, payment, created orders and stock updates.

### PWA / Security
- Prevented authenticated staff HTML and wp-admin requests from being cached by the service worker.
- Added old RAR WSO service-worker cache cleanup.
- Added automatic plugin upgrade/migration routine.
- Added production plugin metadata for WooCommerce dependency, Update URI and GPL licensing.

### QA / Release
- Added PHP, JavaScript and shell syntax validation.
- Added WordPress + WooCommerce runtime smoke tests.
- Added migration, stock, search, order, duplicate-retry, dashboard and PWA endpoint tests.
- Added release-package structure validation.
- Added canonical installable release ZIP packaging.

## 1.0.0 — 2026-09-24

- Added private mobile-first `/staff/` PWA.
- Added dashboard cards for today's orders/sales, low stock and out-of-stock counts.
- Added searchable Stock Manager with protected stock updates.
- Added POS-style manual/social Create Order workflow.
- Added website-price defaults and optional staff line-price override.
- Added customer address, district, shipping charge and order notes.
- Added WooCommerce-native order creation and stock reduction.
- Added dedicated `Woo Stock & Order Staff` role.
- Added HPOS compatibility declaration.
- Added installable manifest/icon/service worker PWA shell.
- Added WooCommerce stock-change logging.
- Added Settings link on the WordPress Plugins screen.
