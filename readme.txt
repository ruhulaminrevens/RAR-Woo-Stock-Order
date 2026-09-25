=== RAR Woo Stock & Order ===
Contributors: ruhulaminrevens
Tags: woocommerce, stock, inventory, pwa, order management, staff, pos
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional mobile-first WooCommerce staff PWA for stock control, fast order entry, manager analytics and shareable sales-order slips.

== Description ==

RAR Woo Stock & Order v1.3.0 provides:

* Professional dashboard with live date/time and daily order/sales metrics.
* Clickable All Stock, Available/Live and Out of Stock cards.
* Color-coded healthy (11+), low (1-10), out (0) and unmanaged stock.
* Advanced Stock Manager with search, filters and protected quantity updates.
* Fast Create Order workflow with Bangladesh phone validation.
* Searchable 64-district and dependent Town/City/Upazila selectors.
* Stock-aware product search where out-of-stock items cannot be added.
* Optional line-price override.
* Fixed or percentage discount.
* Shipping and total in words.
* Save & Share PNG sales-order slip.
* Shop Manager All Orders and Live Orders views.
* Shop Manager status updates.
* 7-day sales chart and growth.
* HPOS compatibility and private-safe PWA caching.

Product add/edit/delete stays in native WooCommerce for Administrator / Shop Manager.

== Installation ==

1. Download the official installable ZIP from the GitHub Release page.
2. Upload in WordPress > Plugins > Add New > Upload Plugin.
3. Activate RAR Woo Stock & Order.
4. Open WooCommerce > Stock & Order.
5. Configure the staff app.
6. Assign staff users the "Woo Stock & Order Staff" role.
7. Open /staff/ and optionally Add to Home Screen.

== Changelog ==

= 1.3.0 =
* Role-aware Staff / Shop Manager operations dashboard with Today, 7-day, monthly and manager 7/30/90-day periods.
* Stock Manager quick-adjust modal with -1, +1, +5, +10, exact quantity and per-product Movement Log.
* Strict +88-prefixed 11-digit Bangladesh mobile entry with server normalization.
* Create Order retains WooCommerce-generated order number, district/town search, color stock search, SL, discount, shipping, total and amount in words.
* Save & Share generates a PNG slip and opens the native mobile share sheet when supported.
* Manager All / Live / Processing order controls with safe 5-minute status Undo.
* Sales & Growth adds trend, Top Products, Payment Mix and Sales Channels.
* Expanded runtime regression coverage for stock movements, analytics, status undo and existing v1.2.1 protections.

= 1.2.1 =
* Fixed dashboard and Manager Control Center queries on stores using custom WooCommerce order statuses.
* Added resilient dashboard retry / refresh behavior and manager chart empty states.
* Added product IMAGE column to the PNG Sales Order Slip.

= 1.2.0 =
* Professional dashboard and manager analytics.
* Color-coded clickable inventory cards and advanced Stock Manager.
* Fixed BDT currency entity rendering.
* Bangladesh phone + district + Town/City/Upazila validation.
* Stock-aware product search and out-of-stock blocking.
* Discounts, total in words and Save & Share PNG slip.
* Shop Manager All Orders / Live Orders with status updates.
* Expanded WordPress + WooCommerce runtime QA.

= 1.1.0 =
* Production hardening release focused on stock + order entry.

= 1.0.0 =
* Initial release.
