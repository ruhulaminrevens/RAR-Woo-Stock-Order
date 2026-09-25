# RAR Woo Stock & Order

[![Validate plugin](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/actions/workflows/validate.yml/badge.svg)](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/actions/workflows/validate.yml)
[![Latest release](https://img.shields.io/github/v/release/ruhulaminrevens/RAR-Woo-Stock-Order)](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/releases/latest)

Professional mobile-first WooCommerce staff PWA for **inventory control, fast order entry, manager order actions, sales analytics and shareable sales-order slips**.

## Current release

**v1.3.1** — critical fix release. **Everyone on v1.3.0 should update**: in v1.3.0 a JavaScript error stopped the staff app at startup (dashboard stuck on "Loading…", stock and order actions not working).

### ⬇️ Direct plugin download

[**Download RAR Woo Stock & Order v1.3.1 — Installable ZIP**](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/raw/main/releases/rar-woo-stock-order-v1.3.1.zip)

Also on the Releases page, published automatically after CI passes: [v1.3.1 Release](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/releases/tag/v1.3.1) · [All releases](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/releases)

**Install / update:** WordPress → Plugins → Add New → Upload Plugin → choose the ZIP → Install Now → **Replace current with uploaded** (when updating) → Activate.

Take a full site backup before updating a live store.

### What v1.3.1 fixes

- The v1.3.0 startup error, which broke the dashboard, stock Save/Quick, product search, Create Order and manager order actions.
- Manager dashboard and order lists crashing on stores that have WooCommerce refunds.
- Checkout-block drafts counted as orders and offered as a status. Pending-payment orders no longer count as sales.
- Refunds refused from the quick status control, since Undo cannot reverse a refund. Use the WooCommerce order screen for refunds.
- Atomic stock updates, so a checkout at the same moment is never overwritten.
- District validation that works on Bangla-translated sites.
- The manager "Today" list after 6 pm.
- `/staff/` right after activation.
- The "Enable staff app" switch now also blocks API calls.
- No empty orders left behind by rejected orders.
- `&amp;` in product names.
- Sales slip overflow on long orders.
- The live clock in the store timezone: `Friday । Sep 25, 2026 । 01:49:02 pm`.
- A discount amount line, and a private audit note for price overrides and discounts.

## Feature set (v1.3.x)

### Role-aware operations dashboard

Staff users receive:
- Today / 7 days / This month order and sales periods
- Orders, Sales, Completed and Returned / Cancelled
- inventory health and stock composition
- 7-day sales snapshot
- Needs Attention
- My Recent Orders
- Create Order / Stock Manager quick actions

Shop Manager users receive all Staff features plus:
- All Orders
- Live Orders
- Total Processing
- status changes with safe 5-minute Undo
- Sales & Growth for 7 / 30 / 90 days
- sales trend
- Top Products
- Payment Mix
- Sales Channels

Inventory semantics:
- **Healthy:** managed stock 11+
- **Low:** managed stock 1–10
- **Out:** zero quantity / out-of-stock
- **Not Tracked:** WooCommerce stock management disabled

### Stock Manager

- item/SKU search
- All / Healthy / Low / Out / Unmanaged filters
- product image, SKU, price, stock state and quantity
- exact stock update through WooCommerce CRUD
- quick-adjust modal with **-1 / +1 / +5 / +10**
- exact quantity save from the modal
- per-product **Movement Log**
- movement user, time, previous quantity, new quantity and delta
- latest 60 manual movement records retained
- load-more pagination

### Create Order

- automatic date
- WooCommerce-generated Order No on save
- fixed **+88** prefix with strict 11-digit Bangladesh mobile entry
- searchable 64-district selector
- dependent searchable Town / City / Upazila selector
- color-coded product availability
- out-of-stock blocking in UI and server validation
- SL number for every line
- quantity and optional permitted price override
- Discount by **৳ amount** or **%**
- Shipping
- Total
- Total In Words
- duplicate retry/idempotency protection

### Save & Share

After saving a WooCommerce order, the app creates a PNG Sales Order Slip containing:

**SL | IMAGE | ITEM | QTY | RATE | AMOUNT**

On supported phones, **Save & Share** opens the native file Share Sheet for WhatsApp, Messenger and other installed compatible apps. If file sharing is unavailable, the PNG downloads instead.

### Shop Manager order controls

- All Orders
- Live Orders
- Processing Orders
- custom WooCommerce statuses
- customer/contact/address summary
- item summary
- status update
- server-issued 5-minute Undo token
- stale Undo protection if the order changes again

### Sales & Growth

For 7 / 30 / 90-day manager periods:
- orders
- sales
- average order value
- items sold
- trend chart
- Top Products
- Payment Mix
- Sales Channels

## Staff App

Default URL:

`https://your-store.com/staff/`

The page is private, capability-protected, marked `noindex,nofollow,noarchive`, and supports Add to Home Screen.

## Roles

### Woo Stock & Order Staff

Can:

- access staff PWA
- search products
- update stock
- create WooCommerce orders
- override order-line price when allowed

Cannot manage the full catalog from this PWA.

### Administrator / Shop Manager

In addition to the staff functions, can:

- use manager dashboard/order controls
- update order status
- manage products normally in WooCommerce → Products

Product creation/edit/delete intentionally remains in native WooCommerce.

## Production safeguards

- nonce on every AJAX write
- capability checks on every action
- HPOS-compatible WooCommerce CRUD
- strict Bangladesh district and city/upazila validation
- Bangladesh phone normalization/validation
- server-side stock validation
- out-of-stock order blocking
- duplicate-order retry/idempotency protection
- discount bounded by item subtotal
- authenticated staff HTML never cached by the service worker
- old RAR WSO service-worker caches removed
- plain-Unicode WooCommerce currency rendering; no raw `&#2547;` / `&nbsp;` leakage
- order/admin links only shown to WooCommerce managers
- operational WooCommerce data preserved on uninstall

## Integration hooks

- `rar_wso_shipping_total`
- `rar_wso_payment_method`
- `rar_wso_payment_method_title`
- `rar_wso_order_created`
- `rar_wso_stock_updated`

These keep the plugin compatible with separate courier, payment and workflow plugins without duplicating their internal logic.

## Settings

Open **WooCommerce → Stock & Order**:

- Enable staff app
- App title
- Staff URL slug
- Default new order status
- Optional line-price override
- Default shipping charge

Assign the **Woo Stock & Order Staff** role from **Users → All Users**.

## Compatibility

- WordPress 6.3+
- PHP 7.4+
- WooCommerce 8.0+
- HPOS supported
- CI runtime tested with WordPress + WooCommerce on PHP 8.2

## QA

The v1.3.1 runtime suite covers:

- v1.2.1 → v1.3.1 upgrade
- checkout drafts excluded, refund tolerance, refused quick refunds
- translated district lookup, disabled-app API block
- 64 Bangladesh district mapping
- district → city/upazila map
- BDT currency Unicode regression
- staff vs manager UI separation
- dashboard inventory counts
- healthy / low / out / unmanaged product classification
- stock update
- quick stock adjustment and Movement Log
- unmanaged → managed stock
- phone validation
- district/city validation
- out-of-stock order rejection
- fixed discount order calculation
- shipping
- total in words
- duplicate-order retry
- stock reduction exactly once
- manager All Orders
- manager status update
- manager status Undo
- Live Orders filtering
- 7/30/90-day analytics payload
- Top Products / Payment Mix / Sales Channels
- PWA manifest/service-worker privacy
- canonical release ZIP structure

See [CHANGELOG.md](CHANGELOG.md) and [RELEASE_NOTES.md](RELEASE_NOTES.md).
