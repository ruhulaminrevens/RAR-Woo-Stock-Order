# RAR Woo Stock & Order

[![Validate plugin](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/actions/workflows/validate.yml/badge.svg)](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/actions/workflows/validate.yml)
[![Latest release](https://img.shields.io/github/v/release/ruhulaminrevens/RAR-Woo-Stock-Order)](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/releases/latest)

Mobile-first **WooCommerce Stock Manager + Staff Order Entry PWA** for teams that mainly work from phones.

## Download

**Recommended installable package**

[⬇️ Download RAR Woo Stock & Order v1.1.0](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/releases/download/v1.1.0/rar-woo-stock-order-v1.1.0.zip)

Use the release asset above for WordPress installation. It contains the canonical plugin folder:

`rar-woo-stock-order/`

## What this plugin is for

RAR Woo Stock & Order intentionally focuses on two staff workflows:

1. **Stock Manager** — quickly search products and update stock from a phone.
2. **Create Order** — create real WooCommerce orders for Facebook, Instagram, phone or chat sales.

Product creation, full product editing and deletion stay in the normal **WooCommerce → Products** screens for Administrator / Shop Manager users. This avoids duplicating WooCommerce catalog management inside the staff app.

## Staff App

Default URL:

`https://your-store.com/staff/`

The app is private, capability-protected and marked `noindex,nofollow,noarchive`.

On supported mobile browsers, use **Add to Home Screen** for an app-like PWA experience.

## Dashboard

- Today's Orders
- Today's Sales
- Low Stock
- Out of Stock
- Stock Manager
- Create Order

## Stock Manager

- Product name / SKU search
- Product image
- Current price and regular price
- Stock status and quantity
- Protected stock updates through WooCommerce CRUD
- Explicit **Set stock** flow for products that do not currently manage quantity
- WooCommerce log entry for staff stock changes (`source: rar-wso`)

## Create Order

Customer fields:

`Customer name | Phone | Email (optional) | Address | Town/City | District`

Order flow:

`Search item → Add → Qty → Price → Shipping → Note → Save Order`

Production protections include:

- Bangladesh district validation on client and server
- Available-stock validation when backorders are disabled
- Optional staff line-price override
- Duplicate-order retry protection using an idempotent request key
- WooCommerce-native order creation and stock reduction
- Standard WooCommerce order-status and email hooks
- Staff users do not receive WooCommerce admin links unless they already have WooCommerce management permission

## Roles

### Woo Stock & Order Staff

Default staff capabilities:

- Access the private staff app
- Search/view products
- Update stock
- Create WooCommerce orders
- Override order-line price when the setting is enabled

The staff app does **not** provide product add/delete controls.

### Administrator / Shop Manager

Continue to use native WooCommerce screens for:

- Add product
- Edit product
- Delete / Trash product
- Full catalog management

## Settings

Open:

**WooCommerce → Stock & Order**

Available settings:

- Enable / disable staff app
- App title
- Staff URL slug
- Default new order status
- Allow item-price override
- Default shipping charge

Assign staff users from:

**Users → All Users → Role → Woo Stock & Order Staff**

## Integration Hooks

The plugin stays decoupled from courier, payment and workflow add-ons. Integrations can use these hooks:

- `rar_wso_shipping_total`
- `rar_wso_payment_method`
- `rar_wso_payment_method_title`
- `rar_wso_order_created`
- `rar_wso_stock_updated`

This keeps RAR Woo Stock & Order focused while allowing other WooCommerce plugins to extend shipping, payment and order workflow behavior.

## Security & Data Safety

- WordPress nonce on every AJAX request
- Capability check on every staff action
- No public inventory/order write endpoint
- WooCommerce CRUD APIs instead of direct order-table writes
- HPOS compatibility declared
- Existing WooCommerce products and orders remain the source of truth
- Authenticated `/staff/` HTML and `wp-admin` requests are not cached by the service worker
- Service worker caches only safe static app assets
- Old RAR WSO caches are removed during service-worker activation
- Uninstall preserves WooCommerce product/order data and plugin operational history

## Upgrade from v1.0.0

Some v1.0.0 installations were installed directly from a GitHub source archive, which created a versioned directory such as:

`RAR-Woo-Stock-Order-1.0.0/`

The official v1.1.0 release uses the canonical folder:

`rar-woo-stock-order/`

For those older installs, use this one-time migration:

1. Back up the site.
2. Deactivate **RAR Woo Stock & Order v1.0.0**.
3. Delete the old plugin files from **Plugins**. The plugin deliberately preserves settings and WooCommerce operational data.
4. Upload the official `rar-woo-stock-order-v1.1.0.zip` release asset.
5. Activate the plugin.
6. Open **WooCommerce → Stock & Order** and verify the staff URL.
7. Test `/staff/` once on desktop and phone.

After this migration, keep the canonical plugin folder for future updates.

## Compatibility

- WordPress 6.3+
- PHP 7.4+
- WooCommerce 8.0+
- WooCommerce HPOS supported
- Tested in CI with a real WordPress + WooCommerce runtime on PHP 8.2

## Quality Assurance

Every release branch is checked with GitHub Actions for:

- PHP syntax
- JavaScript syntax
- Shell test syntax
- v1.0 → v1.1 migration behavior
- Staff role/capability migration
- Shop Manager product-edit capability preservation
- Logged-out and authenticated `/staff/` rendering
- Product/SKU search
- Managed and unmanaged stock updates
- Invalid district rejection
- Stock-limit rejection
- Real WooCommerce order creation
- Duplicate-order retry protection
- Order metadata
- Numeric dashboard sales data
- PWA manifest
- Private-safe service-worker behavior
- Release ZIP structure

## Current Stable

**v1.1.0**

See [CHANGELOG.md](CHANGELOG.md) and [RELEASE_NOTES.md](RELEASE_NOTES.md).
