# Changelog

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
