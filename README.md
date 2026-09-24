# RAR Woo Stock & Order

Mobile-first **WooCommerce Stock Manager + Staff Order Entry PWA** for teams that mainly work from phones.

## Download

**[⬇️ Download stable v1.0.0 installable ZIP](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/archive/refs/heads/v1.0.0.zip)**

**[⬇️ Download latest main ZIP](https://github.com/ruhulaminrevens/RAR-Woo-Stock-Order/archive/refs/heads/main.zip)**

Install: **WordPress → Plugins → Add New → Upload Plugin → choose ZIP → Install Now → Activate**.

> The GitHub archive is directly installable because the plugin bootstrap file is at repository root.

## Staff App

Default URL after activation:

`https://your-store.com/staff/`

Open it on Chrome and choose **Add to Home Screen** for an app-like phone experience.

## Dashboard

- **Today's Orders**
- **Today's Sales**
- **Low Stock**
- **Out of Stock**
- **📦 Stock Manager**
- **🧾 Create Order**

## Stock Manager

Phone-friendly item/SKU search with image, website price, stock status and stock quantity.

Default **Woo Stock & Order Staff** permissions:

- View/search products
- Update stock quantity/status
- Create WooCommerce orders
- Override an order-line price while billing (admin can disable)
- **Cannot add products by default**
- **Cannot delete products by default**

Admin can enable product creation/deletion from **WooCommerce → Stock & Order**. Delete is intentionally safe: it moves the product to WordPress Trash instead of permanently deleting it.

## Create Order

Designed for Facebook / Instagram / chat / phone orders.

Customer information:

`Customer name | Phone | Email (optional) | Address | Town/City | District`

POS-style item workflow:

`Item | Qty | Price | Line total`

Website price is loaded by default. Staff can change the billing price only if admin allows it. A shipping charge and order note can also be added.

Saving creates a real WooCommerce order, triggers normal WooCommerce status hooks/emails, and uses WooCommerce stock-reduction protection.

## Production & Safety Design

- WordPress nonce on every write request
- Capability check on every staff action
- WooCommerce CRUD APIs instead of direct order-table writes
- HPOS compatibility declared
- WooCommerce log entry for staff stock changes (`source: rar-wso`)
- Product deletion is Trash-only
- No public inventory/order write API
- Staff interface marked `noindex,nofollow`
- PWA service worker does not cache WooCommerce/admin AJAX writes
- Existing WooCommerce products and orders remain the source of truth

## Settings

Go to **WooCommerce → Stock & Order**.

Configure:

- Enable/disable staff app
- App title
- Staff URL slug
- Default new order status
- Order price override
- Staff product creation
- Staff product deletion
- Default shipping charge

Assign staff users from **Users → All Users → Role → Woo Stock & Order Staff**.

## Compatibility

- WordPress 6.3+
- PHP 7.4+
- WooCommerce 8.0+
- WooCommerce HPOS supported
- Designed to coexist with normal WooCommerce emails/status hooks and workflow plugins listening to standard status transitions

## Version

Current stable: **v1.0.0**

See [CHANGELOG.md](CHANGELOG.md).
