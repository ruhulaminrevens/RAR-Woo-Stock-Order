# RAR Woo Stock & Order

[![Validate plugin](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/actions/workflows/validate.yml/badge.svg)](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/actions/workflows/validate.yml)
[![Latest release](https://img.shields.io/github/v/release/ruhulaminrevens/RAR-Woo-Stock-Order)](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/releases/latest)

Professional mobile-first WooCommerce staff PWA for **inventory control, fast order entry, manager order actions, sales analytics and shareable sales-order slips**.

## Current release

**v1.2.0**

Installable ZIP:

[Download RAR Woo Stock & Order v1.2.0](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/releases/download/v1.2.0/rar-woo-stock-order-v1.2.0.zip)

## What v1.2.0 adds

### Professional dashboard

- live local date/time
- Today's Orders
- Today's Sales
- Completed Orders
- Returned / Cancelled Orders
- All Stock
- Available / Live Stock
- Out of Stock
- clickable inventory cards
- color-coded inventory health
- 7-day manager sales chart and growth

Inventory colors:

- **Green:** 11+ managed stock
- **Orange:** 1–10 managed stock
- **Red:** 0 / out of stock
- **Blue:** stock quantity not managed yet

### Advanced Stock Manager

- item/SKU search
- filter by All / Healthy / Low / Out / Unmanaged
- color-highlighted product cards
- product image, SKU, selling price, regular price, stock state and quantity
- direct stock updates through WooCommerce CRUD
- explicit **Set stock** action for unmanaged products
- load-more pagination
- stock-change logging

### Advanced Create Order

Order header:

- automatic date
- WooCommerce order number generated on save

Customer details:

- Full Name
- validated Bangladesh mobile number
- optional email for WooCommerce status notifications

Shipping details:

- Full Address
- searchable 64-district selector
- dependent searchable Town / City / Upazila selector
- included Bangladesh district/upazila data map

Order details:

- product/SKU search
- color-coded product availability
- out-of-stock products cannot be added
- serial number for each order item
- quantity
- optional price override
- fixed or percentage discount
- shipping charge
- total
- total in words

### Save & Share

After saving a WooCommerce order, the app builds a **PNG Sales Order Slip** containing:

- order number/date
- customer/contact/address
- item list with SL, quantity, rate and amount
- subtotal
- discount
- shipping
- total
- amount in words
- order note

On supported phones **Save & Share** opens the native share sheet for WhatsApp, Messenger and other installed apps. If file sharing is unavailable, the PNG is downloaded instead.

### Shop Manager Control Center

Administrator / Shop Manager users receive extra controls:

- All Orders
- Live Orders only
- customer/contact/address summary
- item summary
- current status
- status update action
- completed orders automatically disappear from Live Orders
- 7-day sales chart
- week total
- sales growth vs previous week

Normal **Woo Stock & Order Staff** users do not see manager-only controls.

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

The v1.2 runtime suite covers:

- v1.1 → v1.2 upgrade
- 64 Bangladesh district mapping
- district → city/upazila map
- BDT currency Unicode regression
- staff vs manager UI separation
- dashboard inventory counts
- healthy / low / out / unmanaged product classification
- stock update
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
- Live Orders filtering
- 7-day analytics payload
- PWA manifest/service-worker privacy
- canonical release ZIP structure

See [CHANGELOG.md](CHANGELOG.md) and [RELEASE_NOTES.md](RELEASE_NOTES.md).
