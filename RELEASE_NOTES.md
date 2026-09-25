# RAR Woo Stock & Order v1.2.0

**Release date:** 2026-09-25

v1.2.0 upgrades the focused Stock Manager + Staff Order Entry PWA into a more complete professional operations workspace while keeping product catalog management in native WooCommerce.

## Major improvements

- redesigned colorful, clickable dashboard
- live date/time
- richer daily order/sales metrics
- All / Available / Out inventory cards
- healthy / low / out / unmanaged stock color bands
- optimized filtered Stock Manager
- Bangladesh phone validation
- searchable district + dependent Town/City/Upazila selection
- stock-aware product search
- out-of-stock ordering blocked
- serial-numbered order items
- fixed/percentage discount
- total in words
- Save & Share PNG sales-order slip
- Shop Manager All Orders / Live Orders
- manager order status updates
- 7-day sales chart and growth

## Important currency fix

WooCommerce BDT symbols are now normalized to plain Unicode before reaching JavaScript. Raw strings such as:

`0.00&#2547;&nbsp;`

must no longer appear in dashboard, stock, search or order totals.

## Role design

**Woo Stock & Order Staff**

- stock updates
- fast order creation
- optional line-price override

**Shop Manager / Administrator**

- everything above
- All Orders / Live Orders
- status updates
- sales analytics
- regular WooCommerce product management

## Release validation

The automated runtime suite boots a real WordPress + WooCommerce environment and validates stock, orders, discount calculation, Bangladesh address rules, BDT currency, manager actions, analytics and PWA privacy before release.

Use the official release asset:

`rar-woo-stock-order-v1.2.0.zip`
