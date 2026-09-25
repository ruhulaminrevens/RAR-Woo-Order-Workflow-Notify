# Changelog

## 2.0.0 — 2026-09-25 — Documents Center

Replaces **PDF Invoices & Packing Slips for WooCommerce**.

**Documents**
- Added PDF **Invoice**, **Packing Slip** and new **Courier Delivery Label** (100×150 / 100×100 / A6 / 75×100 mm).
- Correct Bangla text shaping via bundled, namespace-isolated mPDF 8.3 + Hind Siliguri font (DejaVu fallback for symbols).
- Amount in words (English "Taka … Only" / Bangla "… টাকা মাত্র", lakh/crore system), PAID / CASH ON DELIVERY stamp, verified-advance deduction and amount to collect, refunds with Net total, notes, terms, signature lines, page numbers, Code-128 barcode, QR code.
- Template overrides in `yourtheme/rar-wow/documents/` and developer filters/actions.

**Invoice numbering**
- New Invoice Register table (`{prefix}rar_wow_invoices`) reserving numbers atomically (unique series+number, retry on collision, per-order lock) — no duplicates under parallel requests.
- Prefix/suffix placeholders, padding, yearly reset, invoice date source, editable next number (cannot go below last issued).
- Automatic invoice number on chosen statuses (default: Confirmed); disable for free orders / chosen statuses.
- Edit number/date/notes on the order screen (logged), void with audit trail (numbers never reused).

**Delivery & access**
- Email attachments for any WooCommerce email and all RAR workflow emails; "Download Invoice" button in RAR customer emails.
- Admin: Documents column, single/bulk printing (merged PDF in new tab), bulk "Create invoice numbers", order-screen box with Create / Email invoice / Copy link / Void and print history; search orders by invoice number.
- Customer: "Invoice (PDF)" in My Account orders and in the Order Status panel; secure order-key guest links; configurable statuses and "only when created" rule.
- Public invoice authenticity verification page via QR (tamper-proof HMAC token, masked customer name).

**Operations**
- WooCommerce → Invoice Register with date filters, search, KPIs and UTF-8 CSV export (formula-injection safe).
- Dashboard widget "Order Workflow Pulse" with one-click printing for confirmed orders.
- Bulk actions: Change status to Confirmed / Shipped / Returned.
- Admin workflow alert email now includes "Open order in admin".
- Tabbed settings: Workflow & Notifications · Documents & Layout · Invoice Numbers & Emails · Tools, Preview & Migration.

**Migration & compatibility**
- One-click import of the old plugin's settings and all invoice numbers (unchanged, duplicates preserved separately); numbering continues after the highest old number. Old invoices remain readable without import.
- Optional compatibility meta `_wcpdf_invoice_number` / `_wcpdf_invoice_date` for tools built for the old plugin.
- Notice while the old plugin is still active.
- HPOS and legacy order storage; PHP 7.4–8.4; WordPress 7.1 / WooCommerce 11.1 tested.
- Automatic DB install/upgrade on first load after "Upload → Replace current" updates.
- Uninstall removes only temp PDFs/font cache; settings, order meta and register are preserved.

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
