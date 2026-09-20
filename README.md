# RAR Woo Order Workflow & Notify

Production-focused WooCommerce order workflow and notification plugin.

## Download

**Latest installable ZIP**

https://github.com/ruhulaminrevens/RAR-Woo-Order-Workflow-Notify/archive/refs/heads/main.zip

**Stable v1.4.0 ZIP**

https://github.com/ruhulaminrevens/RAR-Woo-Order-Workflow-Notify/archive/refs/heads/v1.4.0.zip

> WordPress → Plugins → Add New → Upload Plugin → choose the downloaded ZIP.

## Workflow

`Processing → Confirmed → Shipped → Completed`

Exception/recovery paths:

- `Processing / Confirmed / Shipped → Cancelled`
- `Cancelled → Processing`
- `Completed → Returned`
- `Returned → Processing`

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

1. Download the **Latest installable ZIP** above.
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

**1.4.0 — 2026-09-20**
