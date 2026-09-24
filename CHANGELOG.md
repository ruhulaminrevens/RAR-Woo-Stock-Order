# Changelog

## 1.1.0 — Release candidate

- Removed staff product creation/deletion from the PWA and settings; catalog lifecycle stays with WooCommerce Administrator / Shop Manager.
- Added upgrade migration to remove legacy RAR WSO add/delete capabilities and old settings.
- Fixed malformed WooCommerce currency/HTML output in dashboard, stock and order totals.
- Reworked product search to use WooCommerce product data-store search and added stale-response protection.
- Fixed unmanaged-stock UX so blank stock is not presented as zero.
- Added strict Bangladesh district validation on client and server.
- Added available-stock validation before creating an order when backorders are disabled.
- Added duplicate-order retry protection for network/retry scenarios.
- Added staff session-expiry handling.
- Prevented authenticated staff HTML/admin requests from being cached by the PWA service worker.
- Added old service-worker cache cleanup.
- Added standard extension hooks for shipping, payment, created orders and stock updates.
- Hid WooCommerce admin order links from users without WooCommerce management permission.
- Added automatic plugin upgrade/migration routine.
- Added PHP/JavaScript syntax validation workflow.

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
