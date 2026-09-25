# RAR Woo Stock & Order v1.2.1

**Release date:** 2026-09-25

v1.2.1 is a production hotfix for the v1.2.0 dashboard / manager loading issue seen on stores that register custom WooCommerce order statuses.

## Fixed

- Dashboard metrics now query WooCommerce with normalized registered status slugs.
- Manager Control Center All Orders / Live Orders / drill-down queries support custom order statuses correctly.
- 7-day manager analytics uses the same normalized status handling.
- Inventory metrics still load even if an order-specific metric encounters an exception.
- Dashboard retries automatically after a failed request and refreshes periodically / when the app becomes visible again.
- Manager sales chart shows an explicit empty or warning message rather than a blank panel.

## Sales Order Slip

The PNG slip now uses:

**SL | IMAGE | ITEM | QTY | RATE | AMOUNT**

Product thumbnails are retained from product search and drawn into the slip with a safe fallback if a source image cannot be loaded.

## Validation focus

The runtime suite covers dashboard metrics, manager order APIs, custom WooCommerce statuses, inventory values, order creation, discounts, stock changes, Bangladesh address rules, PWA privacy and release packaging.

Use the official release asset:

`rar-woo-stock-order-v1.2.1.zip`
