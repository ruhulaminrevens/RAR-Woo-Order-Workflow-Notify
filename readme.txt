=== RAR Woo Order Workflow & Notify ===
Contributors: ruhulaminrevens
Tags: woocommerce, pdf invoice, packing slip, order status, delivery label
Requires at least: 6.4
Tested up to: 7.1
WC tested up to: 11.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later

Order workflow, bilingual notifications, order tracking and PDF invoices / packing slips / delivery labels with correct Bangla rendering.

== Description ==

RAR Woo Order Workflow & Notify adds a clean production workflow:

Processing → Confirmed → Shipped → Completed

with Cancelled and Returned exception paths, compact SVG action buttons, customer emails, admin alerts, order notes, courier/tracking details, and review requests.

Version 2.0 adds a complete Documents Center and replaces the "PDF Invoices & Packing Slips for WooCommerce" plugin:

* PDF invoice, packing slip and courier delivery label (100x150 mm thermal, A6 and more).
* Correct Bangla shaping (mPDF + Hind Siliguri), amount in words (English / Bangla).
* Sequential invoice numbers with prefix/suffix placeholders, padding, yearly reset; atomic, audit-safe Invoice Register.
* Automatic invoice number on a chosen status (default Confirmed).
* Attach invoice / packing slip to WooCommerce and RAR emails; invoice download button in emails.
* Admin Documents column, bulk printing, order-screen document box (create, edit, email, void).
* Customer My Account and secure guest downloads.
* Invoice Register with KPIs and CSV export; invoice authenticity QR verification page.
* Dashboard "Order Workflow Pulse" widget; bulk Confirmed / Shipped / Returned status actions.
* One-click migration of settings and invoice numbers from the old PDF plugin.

Workflow highlights:
* HPOS-compatible declaration.
* Custom Confirmed, Shipped and Returned statuses.
* Secure nonce-protected one-click order actions.
* Customer emails: Confirmed, Shipped, Completed, Cancelled, Returned.
* Admin alerts: manual Processing recovery, Cancelled, Returned.
* Admin recipient automatically follows WooCommerce New Order recipient unless overridden.
* Product images, quantities, order total, courier and tracking in emails.
* Completed email includes per-product Rate & Review buttons.
* No extra RAR workflow email call during checkout/order placement.
* Success/failure mail results written to order notes; failures logged under WooCommerce log source `rar-wow`.
* Customer-facing Order Status panel on My Account → View Order and verified Order Tracking results.
* Four-stage progress display plus customer-safe historical order timeline.
* Historical status reconstruction without exposing private admin note text.
* Customer-visible notes, courier/ETA/tracking summary and RAR advance-payment verification events.
* Separate settings for status panel, history and public-note visibility.
* Responsive frontend UI with duplicate native tracking updates suppressed when the unified timeline is active.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate RAR Woo Order Workflow & Notify.
3. Go to WooCommerce → Order Workflow.
4. Keep Admin email blank to reuse WooCommerce New Order recipient, or set a dedicated address.
5. Disable/remove old workflow snippets only after one test order passes.
6. Replacing "PDF Invoices & Packing Slips": Order Workflow → Tools → Import settings → Import invoice numbers → Preview → deactivate the old plugin.

== Upgrade Notice ==

= 2.0.0 =
Documents Center: PDF invoices, packing slips and delivery labels with Bangla support, invoice register, email attachments and migration from PDF Invoices & Packing Slips. Back up before updating, then use Order Workflow → Tools to migrate and deactivate the old PDF plugin.

= 1.5.0 =
Customer Order Experience: View Order + Order Tracking status/progress panel, full customer-safe history, historical status reconstruction, payment/courier summary, privacy-aware note handling, responsive frontend UI and production validation.

= 1.4.0 =
Final production polish: smaller consistent SVG actions, instant workflow admin alerts for Cancelled/Returned/manual Processing recovery, reliable admin recipient resolution, mail success/failure notes/logging, and checkout-safe notification architecture.
