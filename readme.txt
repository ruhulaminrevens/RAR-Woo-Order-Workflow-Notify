=== RAR Woo Order Workflow & Notify ===
Contributors: ruhulaminrevens
Tags: woocommerce, order status, email notification, workflow, returned order
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later

Professional WooCommerce order workflow and notification system for Nabiad.

== Description ==

RAR Woo Order Workflow & Notify adds a clean production workflow:

Processing → Confirmed → Shipped → Completed

with Cancelled and Returned exception paths, compact SVG action buttons, customer emails, admin alerts, order notes, courier/tracking details, and review requests.

Highlights:
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

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate RAR Woo Order Workflow & Notify.
3. Go to WooCommerce → Order Workflow.
4. Keep Admin email blank to reuse WooCommerce New Order recipient, or set a dedicated address.
5. Disable/remove the old WPCode workflow snippets only after one test order passes.

== Upgrade Notice ==

= 1.4.0 =
Final production polish: smaller consistent SVG actions, instant workflow admin alerts for Cancelled/Returned/manual Processing recovery, reliable admin recipient resolution, mail success/failure notes/logging, and checkout-safe notification architecture.
