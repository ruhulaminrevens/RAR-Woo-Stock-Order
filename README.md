# RAR Woo Stock & Order

Mobile-first **WooCommerce Stock Manager + Staff Order Entry PWA** for teams that mainly work from phones.

## Purpose

The plugin has a deliberately narrow production scope:

- staff can view/search products and update stock
- staff can create WooCommerce orders quickly from phone/social/telephone sales
- product creation, full product editing and deletion stay in **WooCommerce → Products** for Administrator / Shop Manager users

This avoids duplicating WooCommerce catalog management inside the staff app.

## Staff App

Default URL:

`https://your-store.com/staff/`

The app is private, capability-protected, marked `noindex,nofollow`, and can be installed from Chrome using **Add to Home Screen**.

## Dashboard

- Today's Orders
- Today's Sales
- Low Stock
- Out of Stock
- Stock Manager
- Create Order

## Stock Manager

- item/SKU search
- product image
- current and regular price
- stock status and quantity
- protected stock updates
- unmanaged-stock products require an explicit quantity before stock management is enabled
- WooCommerce log entry for stock changes (`source: rar-wso`)

## Create Order

Customer fields:

`Customer name | Phone | Email (optional) | Address | Town/City | District`

Order workflow:

`Search item → Add → Qty → Price → Shipping → Note → Save Order`

Production protections include:

- Bangladesh district validation
- available-stock validation when backorders are not allowed
- optional staff line-price override
- duplicate-order retry protection using an idempotent request key
- WooCommerce-native order CRUD and stock reduction
- standard WooCommerce status/email hooks
- extension hooks for shipping/payment integrations

Available integration hooks:

- `rar_wso_shipping_total`
- `rar_wso_payment_method`
- `rar_wso_payment_method_title`
- `rar_wso_order_created`
- `rar_wso_stock_updated`

## Security & PWA

- WordPress nonce on every AJAX request
- capability check on every staff action
- no public inventory/order write endpoint
- HPOS compatibility declared
- authenticated `/staff/` HTML and `wp-admin` requests are never cached by the service worker
- service worker caches only the app's safe static assets and removes old RAR WSO caches
- existing WooCommerce products/orders remain the source of truth
- uninstall preserves operational WooCommerce data

## Settings

Go to **WooCommerce → Stock & Order**.

Configure:

- enable/disable staff app
- app title
- staff URL slug
- default new order status
- item-price override
- default shipping charge

Assign staff users the role **Woo Stock & Order Staff** from **Users → All Users**.

Administrator and Shop Manager continue to use the normal WooCommerce product screens for catalog add/edit/delete.

## Compatibility

- WordPress 6.3+
- PHP 7.4+
- WooCommerce 8.0+
- WooCommerce HPOS supported

## Version

Release candidate: **v1.1.0**

The current public stable branch remains v1.0.0 until v1.1.0 validation is completed and merged.

See [CHANGELOG.md](CHANGELOG.md).
