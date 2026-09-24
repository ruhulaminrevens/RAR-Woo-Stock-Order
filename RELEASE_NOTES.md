# RAR Woo Stock & Order v1.1.0

**Release date:** 2026-09-25

v1.1.0 is the first production-hardening release of RAR Woo Stock & Order. It narrows the staff PWA to the two workflows it is meant to handle best: **stock updates** and **fast WooCommerce order entry**.

## Highlights

- Removed Product Add/Delete from the staff PWA.
- Kept full product lifecycle management in native WooCommerce for Administrator / Shop Manager.
- Fixed malformed currency rendering such as raw HTML entities appearing beside prices.
- Reworked product/SKU search and added stale-request protection.
- Improved unmanaged-stock handling.
- Added Bangladesh district validation.
- Added available-stock validation before staff order creation.
- Added duplicate-order retry protection so a repeated network request does not create/reduce stock twice.
- Hardened authenticated PWA/service-worker caching.
- Added upgrade migration for legacy v1.0.0 settings/capabilities.
- Added integration hooks for courier/payment/workflow extensions.
- Added full WordPress + WooCommerce runtime smoke tests.

## Validation

The v1.1.0 release candidate passed:

- PHP syntax
- JavaScript syntax
- Shell syntax
- v1.0 → v1.1 migration
- Staff capability migration
- Shop Manager product-edit capability preservation
- Logged-out /staff/ login rendering
- Authenticated staff PWA rendering
- SKU search
- No-match search
- Managed stock update
- Unmanaged → managed stock flow
- Invalid district rejection
- Excess-stock order rejection
- Real WooCommerce order creation
- Duplicate retry protection
- Stock reduction exactly once
- Order metadata persistence
- Numeric dashboard sales data
- PWA manifest
- Service-worker private-cache protections
- Release package structure

## Install / Upgrade

Use the attached:

`rar-woo-stock-order-v1.1.0.zip`

Do not use GitHub's automatically generated source ZIP for WordPress installation if you are upgrading from an older versioned-folder installation.

For v1.0.0 installs created from a GitHub source archive:

1. Take a backup.
2. Deactivate the old RAR Woo Stock & Order plugin.
3. Delete the old plugin files.
4. Upload the official v1.1.0 release ZIP.
5. Activate it.
6. Verify WooCommerce → Stock & Order and /staff/.

The plugin preserves settings and WooCommerce operational data during uninstall.
