# Changelog

## 1.5.0 — 2026-09-23

- Added a professional customer-facing **Order Status** panel to My Account → View Order and verified WooCommerce Order Tracking results.
- Added a responsive four-stage progress indicator: Received → Confirmed → Shipped → Completed, with exception handling for Cancelled, Returned, Failed and Refunded.
- Added full **Order History** timeline with newest updates first.
- Added safe historical reconstruction from trusted WooCommerce status-change records so older orders can display meaningful status history.
- Added customer-visible WooCommerce notes to the unified history while keeping private admin notes hidden.
- Added RAR advance-payment submission/verification events without exposing payer reference, Transaction ID, PIN/OTP or internal verification data.
- Added courier, ETA and tracking summary with compatibility for RAR Woo Smart Courier and common tracking meta keys.
- Added frontend CSS/JS to integrate the tracking-page native update list into the unified RAR timeline without duplicate display.
- Added WooCommerce → Order Workflow settings for customer status panel, full history and public-note visibility.
- Kept checkout performance safe: no new remote request or workflow email is added to the Place Order path.
- Removed hardcoded store branding from plugin defaults; saved site settings continue to control the live brand name.
- Added GitHub Actions validation for PHP 7.4/8.3 syntax, JavaScript syntax and required release files.
- Updated WooCommerce tested compatibility to 11.1.


## 1.4.0 — 2026-09-20

- Renamed production package to **RAR Woo Order Workflow & Notify**.
- Hardened workflow transitions: Processing → Confirmed → Shipped → Completed; Cancelled/Returned recovery paths.
- Added/retained `Confirmed`, `Shipped`, and `Returned` WooCommerce statuses.
- Rebuilt admin action buttons with compact 30×30 SVG controls and 28×28 mobile sizing.
- Added immediate admin workflow alerts for manual Processing recovery, Cancelled, and Returned.
- Admin recipient now defaults to the WooCommerce **New order** recipient before falling back to WordPress admin email.
- Added send-success / send-failure order notes and WooCommerce logger entries (`rar-wow`) for troubleshooting.
- Customer notifications remain bilingual and include product thumbnails, quantities, courier/tracking, status progress, and order link.
- Completed email keeps unique thank-you + per-product review CTA.
- Checkout-safe architecture: Processing admin workflow mail is limited to explicit workflow recovery actions, so normal checkout does not gain an extra synchronous RAR mail call.
- Suppresses duplicate WooCommerce core Cancelled-admin and Customer-completed emails while RAR owns those corresponding notifications; core mail automatically resumes if the RAR setting is disabled.
- HPOS compatibility declared.
- Plugin uninstall intentionally preserves order history/metadata and settings.

## 1.3.0

- Added compact status action workflow and improved SVG icons.
- Added Cancelled and Returned paths with customer/admin notifications.
- Added progress indicator to Confirmed/Shipped/Completed emails.
- Added product-specific feedback/rating CTAs on completion.

## 1.0.0

- Initial WooCommerce order workflow and notification system.
