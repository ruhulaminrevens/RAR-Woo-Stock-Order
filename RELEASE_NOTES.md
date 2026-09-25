# RAR Woo Stock & Order v1.3.0

**Release date:** 2026-09-25

v1.3.0 expands the v1.2.1 stable baseline into a role-aware WooCommerce operations workspace while preserving the existing stock/order protections.

## Dashboard

### Staff
- Today / 7 days / This month order and sales periods.
- Orders, sales, completed and returned/cancelled metrics.
- Inventory health with Healthy, Low, Out and Not Tracked semantics.
- 7-day sales snapshot.
- Needs Attention.
- My Recent Orders.
- Quick links to Create Order and Stock Manager.

### Shop Manager
Everything in Staff plus:
- All Orders.
- Live Orders.
- Total Processing.
- status updates with a safe 5-minute **Undo** action.
- Sales & Growth reporting for 7 / 30 / 90 days.
- sales trend.
- Top Products.
- Payment Mix.
- Sales Channels.

## Stock Manager

- Search by item name or SKU.
- All / Healthy / Low / Out / Unmanaged filters.
- Existing exact quantity update remains available.
- New quick-adjust modal:
  - -1
  - +1
  - +5
  - +10
  - exact quantity save
- Per-product **Movement Log** records:
  - user
  - time
  - previous quantity
  - new quantity
  - delta
  - update source
- Manual stock history keeps the latest 60 entries.
- WooCommerce product CRUD remains the source of truth.

## Create Order

- Date is automatic.
- Order No is assigned by WooCommerce when the order is saved.
- +88 prefix with strict 11-digit Bangladesh mobile entry.
- searchable District selector.
- searchable dependent Town / City / Upazila selector.
- color-coded product search.
- out-of-stock products cannot be added.
- SL number on each item.
- quantity and permitted price override.
- Discount by amount or percentage.
- Shipping.
- Total.
- Total In Words.
- duplicate retry protection.

## Save & Share

After save, the PWA creates a PNG Sales Order Slip containing:

**SL | IMAGE | ITEM | QTY | RATE | AMOUNT**

On supported mobile browsers, **Save & Share** opens the native file Share Sheet so the slip can be sent through WhatsApp, Messenger or any compatible installed app. If native file sharing is unavailable, the PNG is downloaded.

## Compatibility / safety

- WordPress 6.3+
- PHP 7.4+
- WooCommerce 8.0+
- HPOS declared compatible.
- Custom WooCommerce statuses remain supported.
- Staff and Shop Manager capabilities remain separated.
- nonce and capability checks protect every AJAX write.
- strict Bangladesh phone / address validation remains server-side.
- authenticated staff HTML is excluded from service-worker caching.

## Validation

The final runtime suite covers:

- upgrade to v1.3.0
- role/capability preservation
- 64 Bangladesh districts
- custom WooCommerce statuses
- dashboard period data
- inventory classification
- exact stock update
- quick stock adjustment
- Movement Log API
- unmanaged → managed stock
- Bangladesh mobile validation
- district / town validation
- out-of-stock order rejection
- discount / shipping / total / amount in words
- duplicate-order protection
- stock reduction exactly once
- All / Today / Processing / Completed / Live manager order modes
- status update + Undo
- manager sales analytics
- Top Products / Payment Mix / Sales Channels
- PWA privacy
- BDT entity regression
- PNG slip IMAGE regression
- native file-share capability guard
