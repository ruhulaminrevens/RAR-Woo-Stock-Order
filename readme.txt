=== RAR Woo Stock & Order ===
Contributors: ruhulaminrevens
Tags: woocommerce, stock, inventory, pwa, order management, staff
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mobile-first WooCommerce staff PWA for controlled stock updates and fast manual/social order creation.

== Description ==

RAR Woo Stock & Order adds a private staff web app (default /staff/) designed for phone-first stock management and manual/social order entry.

Features:
* Dashboard with Today's Orders, Today's Sales, Low Stock and Out of Stock.
* Stock Manager with item/SKU search, product image, current price and protected stock updates.
* Create Order workflow for customer details, products, quantity, price, shipping and notes.
* Bangladesh district and available-stock validation.
* Optional staff order-line price override.
* Duplicate-order retry protection.
* Dedicated Woo Stock & Order Staff role.
* Product add/edit/delete remains in WooCommerce for Administrator / Shop Manager.
* WooCommerce-native order creation, stock reduction and status/email hooks.
* HPOS compatibility.
* Nonce/capability protected writes.
* Private-safe PWA service-worker caching.
* Upgrade migration for v1.0.0 settings/capabilities.

== Installation ==

1. Download the official installable ZIP from the GitHub Release page.
2. Upload it in WordPress > Plugins > Add New > Upload Plugin.
3. Activate RAR Woo Stock & Order.
4. Open WooCommerce > Stock & Order.
5. Configure the staff app, default order status, price override and default shipping.
6. Assign staff users the role "Woo Stock & Order Staff".
7. Open /staff/ on the phone and use Chrome > Add to Home Screen.

== Upgrade Notice ==

= 1.1.0 =
If v1.0.0 was installed directly from a GitHub source archive and uses a versioned plugin folder, deactivate/delete the old plugin files first, then install the official v1.1.0 release ZIP. Settings and WooCommerce operational data are preserved.

== Changelog ==

= 1.1.0 =
* Focused staff app on stock updates and order entry; product lifecycle management stays in WooCommerce.
* Fixed currency rendering, product search and unmanaged-stock UX.
* Added district/stock validation and duplicate-order protection.
* Hardened private PWA caching and upgrade migration.
* Added integration hooks for shipping/payment/workflow extensions.
* Added WordPress + WooCommerce runtime smoke tests and canonical release packaging.

= 1.0.0 =
* Initial release.
