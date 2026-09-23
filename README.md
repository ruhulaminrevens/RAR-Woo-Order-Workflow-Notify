# RAR Woo Order Workflow & Notify

Production-focused WooCommerce order workflow and notification plugin.

## Download

### [⬇️ Download Latest Installable ZIP](https://github.com/ruhulaminrevens/RAR-Woo-Order-Workflow-Notify/archive/refs/heads/main.zip)

### [📦 Download Stable v1.5.0 ZIP](https://github.com/ruhulaminrevens/RAR-Woo-Order-Workflow-Notify/archive/refs/heads/v1.5.0.zip)

**Install:** WordPress → Plugins → Add New → Upload Plugin → choose the downloaded ZIP → Install Now → Activate.

## Workflow

`Processing → Confirmed → Shipped → Completed`

Exception/recovery paths:

- `Processing / Confirmed / Shipped → Cancelled`
- `Cancelled → Processing`
- `Completed → Returned`
- `Returned → Processing`

## Customer Order Status & History

v1.5.0 adds a production customer-facing order experience to both WooCommerce surfaces:

- **My Account → View Order** — the email **View Order / অর্ডার দেখুন** button now leads to an order page with a clear current-status badge, progress bar and full order history.
- **Order Tracking** — after WooCommerce verifies the Order ID + billing email, the same order-status/history panel appears below the tracking result.
- **Historical orders** — trusted WooCommerce status-change records are reconstructed into a customer-safe timeline, so older orders can show status history without exposing private note text.
- **Public order notes** — notes explicitly marked visible to the customer are merged into the timeline.
- **Advance payment** — RAR advance-payment submission and manual verification are shown without exposing payer reference, Transaction ID, PIN/OTP or internal admin details.
- **Courier summary** — courier, ETA and tracking details are shown when available.
- **Privacy by design** — private admin notes, email logs and internal operational notes are never printed to customers.
- **No checkout slowdown** — the feature performs history reconstruction when the customer views an order; it does not add a remote call or extra email to the Place Order request.

The display can be controlled from **WooCommerce → Order Workflow** using separate toggles for the customer status panel, order history and customer-visible notes.

## Notifications

| Event | Customer | Admin |
|---|---:|---:|
| Processing | — | ✅ on manual recovery |
| Confirmed | ✅ | — |
| Shipped | ✅ | — |
| Completed | ✅ + review CTA | — |
| Cancelled | ✅ | ✅ instant workflow alert |
| Returned | ✅ | ✅ instant workflow alert |

Admin workflow mail uses the WooCommerce **New order** recipient by default, so it follows the same operational mailbox already used for new-order alerts.

## Performance design

This plugin deliberately **does not send an extra workflow email on the checkout/order-placement request**. Normal WooCommerce New Order / Processing mail behaviour remains available. That keeps it out of the critical `Place Order` path.

If checkout still spins longer than expected, inspect SMTP round-trip time, PDF invoice attachment generation, courier recalculation/AJAX, cache exclusions, and host/PHP performance separately.

## Admin actions

Compact SVG actions are status-aware and only expose allowed transitions.

- Desktop: **30×30 px**
- Narrow/mobile admin: **28×28 px**
- Distinct Processing / Confirmed / Shipped / Complete / Cancel / Return SVG icons
- Nonce-protected transitions with permission checks

## Email reliability

- Cancelled and Returned trigger an immediate admin workflow alert.
- Admin recipient defaults to WooCommerce → Emails → **New order** recipient.
- Success/failure is written to order notes.
- Failed mail calls are also logged in WooCommerce logs with source `rar-wow`.
- The plugin suppresses WooCommerce's separate admin-only Cancelled email and default Customer Completed email while RAR owns those notifications, preventing duplicates.

## Install

1. Download the **Latest Installable ZIP** above.
2. WordPress → Plugins → Add New → Upload Plugin.
3. Activate **RAR Woo Order Workflow & Notify**.
4. WooCommerce → **Order Workflow**.
5. Leave Admin notification email blank to inherit WooCommerce → Emails → New order recipient.
6. Place one test COD order and verify Confirmed → Shipped → Completed, then Completed → Returned and Cancelled → Processing.
7. Only after the test passes, remove obsolete WPCode workflow snippets / old status-manager plugin.

## Compatibility

- WordPress 6.4+
- WooCommerce 8.0+
- PHP 7.4+
- WooCommerce HPOS compatible

## Version

**1.5.0 — 2026-09-23**

Customer Order Experience release: professional View Order + Order Tracking status panel, customer-safe historical timeline, payment/courier summary, responsive frontend UI, and privacy-aware history reconstruction.
