# RAR Woo Order Workflow & Notify

Production WooCommerce order workflow, bilingual notifications, customer order tracking **and a built-in Documents Center** — PDF invoices, packing slips and courier delivery labels. Version 2.0 replaces the *PDF Invoices & Packing Slips for WooCommerce* plugin, so you can remove it.

## Download

### [⬇️ Download Latest Installable ZIP](https://github.com/ruhulaminrevens/RAR-Woo-Order-Workflow-Notify/archive/refs/heads/main.zip)

**Install / update:** WordPress → Plugins → Add New → Upload Plugin → choose the ZIP → **Replace current with uploaded** → Activate.
The database table and defaults are created automatically on the first page load after the update.

---

## What's new in 2.0 — Documents Center

| Feature | Details |
|---|---|
| **PDF Invoice** | Logo, shop identity, BIN/VAT/TIN, Bill-to / Ship-to, SKU, item meta, optional thumbnails, discounts, shipping, fees, refunds, **Net total**, amount in words (English *Taka … Only* and/or Bangla *… টাকা মাত্র*), PAID / CASH ON DELIVERY stamp, advance-payment deduction, **amount to collect**, notes, terms, signature lines, page numbers. |
| **Packing Slip** | No prices. Pick-list checkboxes, SKU, weights, total quantity, courier + tracking, **COLLECT ৳X** or **PREPAID** stamp, Code-128 barcode, Packed by / Checked by / Courier received lines. |
| **Delivery Label** *(new)* | 100×150 mm (4×6 in thermal), 100×100, A6 or 75×100. Recipient, big phone number, compact address, **COD amount**, courier, tracking barcode, contents, QR code. |
| **Correct Bangla rendering** | Uses an OpenType-shaping engine (mPDF) with the Hind Siliguri font — conjuncts like ক্ষ, ন্ড, স্ত্র and vowel signs render correctly. The old plugin's engine (dompdf) breaks Bangla text. |
| **Sequential invoice numbers** | Prefix/suffix placeholders (`[invoice_year]`, `[invoice_month]`, `[yy]`, `[order_number]`, `[invoice_date="ymd"]` …), zero-padding, yearly reset, invoice date = creation date or order date. Numbers are reserved atomically in the register — parallel orders can never receive the same number (tested with 12 simultaneous requests). Voided numbers are never reused. |
| **Automatic invoice at status** | e.g. create the invoice number when an order becomes **Confirmed** (default). Only the number is assigned — no PDF is rendered, so it is instant. |
| **Email attachments** | Attach invoice and/or packing slip to any WooCommerce email *and* the RAR workflow emails (Confirmed / Shipped / Completed / Cancelled / Returned / Admin alert). Default: WooCommerce *Customer invoice* + RAR *Completed*. |
| **Download button in emails** | "Download Invoice / ইনভয়েস" next to "View Order" in RAR customer emails. |
| **Admin printing** | Documents column in the Orders list (invoice number + Invoice / Slip / Label buttons with printed indicator), bulk **Print Invoices / Packing Slips / Delivery Labels** (one merged PDF, opens in a new tab), bulk **Create invoice numbers**. |
| **Order screen box** | Invoice number/date, edit number/date/notes (logged), Create, **Email invoice to customer**, copy customer link, **Void** (number kept for audit), print history. |
| **Customer access** | "Invoice (PDF)" in My Account → Orders and in the Order Status panel; secure order-key links for guests; statuses and "only when created" rules configurable. |
| **Invoice Register** *(new)* | WooCommerce → Invoice Register: every issued number with invoice date, customer, phone, city, payment method, current status, subtotal, discount, shipping, fees, tax, total, refunded, advance. Date filters, search, KPIs (gross, refunded, net, discounts, shipping, advance) and **CSV export (UTF-8, Excel/Tally-ready)**. |
| **Invoice authenticity QR** *(new)* | The invoice QR opens a public verification page (“Genuine invoice”, masked customer name, amount, current status). Tampered links fail. Alternative: QR to the customer order-status page. |
| **Dashboard widget** *(new)* | *Order Workflow Pulse*: On-hold / To confirm / To pack & ship / In transit / Returned counts, invoices today & this month, **one-click print of all packing slips / labels for confirmed orders**. |
| **Bulk workflow statuses** *(new)* | Orders list bulk actions: *Change status to Confirmed / Shipped / Returned* — customer emails and auto-invoice fire exactly as with the single buttons. |
| **Migration from the old plugin** | Tools tab: import settings (shop, logo, footer, paper, prefix/suffix/padding, yearly reset, display options, email attachments) and **import all old invoice numbers unchanged**; numbering continues after the old plugin's highest number. Old invoices remain readable even without import. |
| **Compatibility meta** | Also writes `_wcpdf_invoice_number` / `_wcpdf_invoice_date` so exports, courier and staff tools built for the old plugin keep working. |
| **Template overrides** | Copy `templates/documents/*.php|css` to `yourtheme/rar-wow/documents/`. Hooks: `rar_wow_document_data`, `rar_wow_document_css`, `rar_wow_formatted_invoice_number`, `rar_wow_invoice_allowed`, `rar_wow_pdf_config`, `rar_wow_document_filename`, actions `rar_wow_invoice_created`, `rar_wow_invoice_voided`, `rar_wow_document_before/after`. |

### Performance
- PDF library is loaded **only** when a document is generated. Normal pages and checkout carry no extra code.
- Typical render time on this build: invoice 0.1–0.2 s, packing slip / label 0.03–0.07 s.
- Auto-invoice at a status only writes the number (no PDF).
- Email attachment files are cleaned up automatically (no WP-Cron dependency).
- Avoid attaching PDFs to *New order* / *Processing order* emails if checkout speed matters — each attachment renders a PDF during that request.

### Security
- Admin documents: nonce + `edit_shop_orders` capability. Customer documents: order-key match + owner check (guest links optional). Customers can only download invoices, never packing slips/labels.
- PDFs are sent with `no-store` / LiteSpeed no-cache headers; temp folders are protected with `.htaccess` + `index.php`.
- CSV export neutralises spreadsheet formula injection.
- Vendor libraries are namespace-isolated (`RarWowVendor\…`) and cannot conflict with other plugins' mPDF/PSR copies.

---

## Replacing "PDF Invoices & Packing Slips for WooCommerce"

1. Take a full backup (files + database).
2. Update this plugin to 2.0 and open **WooCommerce → Order Workflow → Tools, Preview & Migration**.
3. Click **Import settings**, then **Import invoice numbers**. The screen shows how many old invoices were found and the next number.
4. Review **Documents & Layout** (logo, address, BIN, paper size) and **Invoice Numbers & Emails** (prefix, next number, which emails get the PDF).
5. Use **Preview documents** for one old order and one new order.
6. Deactivate and delete *PDF Invoices & Packing Slips for WooCommerce*. Old invoice numbers stay on the orders and in the RAR register.

**বাংলা সংক্ষেপ:** ব্যাকআপ নিন → প্লাগইন আপডেট করুন → Tools ট্যাব থেকে *Import settings* ও *Import invoice numbers* চাপুন → Preview করে দেখুন → পুরনো PDF Invoices প্লাগইন Deactivate/Delete করুন। পুরনো ইনভয়েস নম্বর বদলাবে না, নতুন নম্বর পুরনো সর্বোচ্চ নম্বরের পর থেকে চলবে।

---

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
| Completed | ✅ + review CTA (+ invoice PDF by default) | — |
| Cancelled | ✅ | ✅ instant workflow alert |
| Returned | ✅ | ✅ instant workflow alert |

Admin workflow mail uses the WooCommerce **New order** recipient by default and now includes an *Open order in admin* button.

## Customer Order Status & History

- **My Account → View Order** and **Order Tracking**: current-status badge, four-stage progress, full customer-safe history, courier/ETA/tracking, payment/advance summary, and an **Invoice (PDF)** button when available.
- Private admin notes and internal logs are never shown to customers.

## Settings (WooCommerce → Order Workflow)

| Tab | Contents |
|---|---|
| Workflow & Notifications | Admin email, brand name, support line, customer emails, admin alerts, status panel, history, public notes |
| Documents & Layout | Enable documents, logo, shop identity, BIN/VAT, accent colour, paper & label size, titles, what to show |
| Invoice Numbers & Emails | Next number, prefix/suffix, padding, yearly reset, invoice date, auto-invoice statuses, free orders, email attachments, customer access, guest links |
| Tools, Preview & Migration | Preview any order (PDF/HTML), system check, clear cache, migrate from the old plugin |

## Requirements

- WordPress 6.4+, WooCommerce 8.0+ (tested 11.1), PHP 7.4–8.4
- PHP extensions: `mbstring`, `gd`, `zlib` (standard on Hostinger)
- HPOS and legacy order storage both supported

## Tested

WordPress 7.1.2 + WooCommerce 11.1.2 on PHP 8.4, both HPOS and legacy post storage: workflow transitions and emails, auto-invoice, attachments to WooCommerce and RAR emails, admin single/bulk printing, bulk status changes, order-screen create/edit/void/email, customer and guest downloads with access rules, verification page, register + CSV, refunds, parallel numbering, migration from PDF Invoices & Packing Slips 5.16.3 (including duplicate legacy numbers). PHPCompatibility scan clean for PHP 7.4+.

## Uninstall

Deleting the plugin removes only temporary PDF files and font cache. Settings, order meta (invoice numbers, history) and the Invoice Register are preserved.
