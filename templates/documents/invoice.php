<?php
/**
 * Invoice template (mPDF).
 *
 * Override: copy to yourtheme/rar-wow/documents/invoice.php
 *
 * @var array $d Document data from RAR_WOW_Documents::build_data().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$s   = $d['settings'];
$pay = $d['pay'];
?>
<htmlpagefooter name="rarfooter">
    <table class="footer"><tr>
        <td><?php echo esc_html( $s['footer'] ); ?></td>
        <td class="right"><?php echo esc_html( $d['shop']['name'] ); ?> · Invoice <?php echo esc_html( $d['invoice_number'] ); ?> · Page {PAGENO} of {nbpg}</td>
    </tr></table>
</htmlpagefooter>
<sethtmlpagefooter name="rarfooter" value="on" show-this-page="1" />

<?php do_action( 'rar_wow_document_before', $d ); ?>

<table class="head"><tr>
    <td style="width:58%;">
        <?php if ( $d['shop']['logo'] ) : ?>
            <div class="logo"><img src="<?php echo esc_attr( $d['shop']['logo'] ); ?>" alt=""></div>
        <?php else : ?>
            <div class="brand-name"><?php echo esc_html( $d['shop']['name'] ); ?></div>
        <?php endif; ?>
        <div class="shop-lines">
            <?php if ( $d['shop']['logo'] ) : ?><strong><?php echo esc_html( $d['shop']['name'] ); ?></strong><br><?php endif; ?>
            <?php echo $d['shop']['address'] ? $d['shop']['address'] . '<br>' : ''; // Escaped in build_data(). ?>
            <?php
            $contact = array_filter( array( $d['shop']['phone'] ? 'Phone: ' . $d['shop']['phone'] : '', $d['shop']['email'], $d['shop']['website'] ) );
            echo esc_html( implode( '  ·  ', $contact ) );
            ?>
            <?php if ( $d['shop']['tax'] ) : ?><br><?php echo esc_html( $d['shop']['tax'] ); ?><?php endif; ?>
            <?php if ( $d['shop']['extra'] ) : ?><br><?php echo $d['shop']['extra']; // Escaped in build_data(). ?><?php endif; ?>
        </div>
    </td>
    <td style="width:42%;" class="right">
        <div class="doc-title"><?php echo esc_html( $d['title'] ); ?></div>
        <?php if ( $d['subtitle'] ) : ?><div class="doc-subtitle"><?php echo esc_html( $d['subtitle'] ); ?></div><?php endif; ?>
        <table class="meta" align="right">
            <tr><td class="k">Invoice No.</td><td class="v"><?php echo esc_html( $d['invoice_number'] ? $d['invoice_number'] : '—' ); ?></td></tr>
            <tr><td class="k">Invoice Date</td><td class="v"><?php echo esc_html( $d['invoice_date'] ? $d['invoice_date'] : '—' ); ?></td></tr>
            <tr><td class="k">Order No.</td><td class="v">#<?php echo esc_html( $d['order_number'] ); ?></td></tr>
            <tr><td class="k">Order Date</td><td class="v"><?php echo esc_html( $d['order_date'] ); ?></td></tr>
            <?php if ( 'yes' === $s['show_payment_method'] && $d['payment_method'] ) : ?>
                <tr><td class="k">Payment</td><td class="v"><?php echo esc_html( $d['payment_method'] ); ?></td></tr>
            <?php endif; ?>
        </table>
    </td>
</tr></table>

<div class="bar"></div>

<table class="parties"><tr>
    <td class="col" style="width:<?php echo $d['shipping_html'] ? '37%' : '74%'; ?>;">
        <div class="label-cap">Bill To</div>
        <div class="party"><?php echo $d['billing_html']; // Escaped in build_data(). ?></div>
        <?php if ( $d['phone'] ) : ?><div class="party">Phone: <strong><?php echo esc_html( $d['phone'] ); ?></strong></div><?php endif; ?>
        <?php if ( $d['email'] ) : ?><div class="party"><?php echo esc_html( $d['email'] ); ?></div><?php endif; ?>
    </td>
    <?php if ( $d['shipping_html'] ) : ?>
        <td class="col" style="width:37%;">
            <div class="label-cap">Ship To</div>
            <div class="party"><?php echo $d['shipping_html']; // Escaped in build_data(). ?></div>
            <?php if ( $d['shipping_phone'] ) : ?><div class="party">Phone: <strong><?php echo esc_html( $d['shipping_phone'] ); ?></strong></div><?php endif; ?>
        </td>
    <?php endif; ?>
    <td class="qr-cell">
        <?php if ( $d['qr'] ) : ?>
            <barcode code="<?php echo esc_attr( $d['qr'] ); ?>" type="QR" size="0.72" error="M" disableborder="1" />
            <div class="qr-cap"><?php echo 'verify' === $s['qr_mode'] && $d['invoice_number'] ? 'Scan to verify invoice' : 'Scan to track order'; ?></div>
        <?php endif; ?>
    </td>
</tr></table>

<table class="items">
    <thead>
        <tr>
            <th class="idx">#</th>
            <?php if ( 'yes' === $s['show_thumbnails'] ) : ?><th style="width:13mm;"></th><?php endif; ?>
            <th>Item</th>
            <?php if ( 'yes' === $s['show_sku'] ) : ?><th style="width:22mm;">SKU</th><?php endif; ?>
            <th class="qty" style="width:12mm;">Qty</th>
            <th class="num" style="width:26mm;">Unit Price</th>
            <th class="num" style="width:28mm;">Amount</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $d['items'] as $i => $item ) : ?>
        <tr class="<?php echo 1 === $i % 2 ? 'alt' : ''; ?>">
            <td class="idx"><?php echo esc_html( $item['index'] ); ?></td>
            <?php if ( 'yes' === $s['show_thumbnails'] ) : ?>
                <td><?php if ( $item['thumb'] ) : ?><img class="thumb" src="<?php echo esc_attr( $item['thumb'] ); ?>" alt="" style="width:11mm;height:11mm;"><?php endif; ?></td>
            <?php endif; ?>
            <td>
                <div class="item-name"><?php echo esc_html( $item['name'] ); ?></div>
                <?php if ( $item['meta'] ) : ?><div class="item-meta"><?php echo esc_html( $item['meta'] ); ?></div><?php endif; ?>
                <?php if ( $item['refunded'] ) : ?><div class="refunded">Refunded qty: <?php echo esc_html( $item['refunded'] ); ?></div><?php endif; ?>
            </td>
            <?php if ( 'yes' === $s['show_sku'] ) : ?><td class="muted"><?php echo esc_html( $item['sku'] ? $item['sku'] : '—' ); ?></td><?php endif; ?>
            <td class="qty"><?php echo esc_html( $item['qty'] ); ?></td>
            <td class="num"><?php echo esc_html( $item['unit'] ); ?></td>
            <td class="num strong"><?php echo esc_html( $item['line'] ); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<table class="summary"><tr>
    <td class="left">
        <?php if ( $d['amount_words'] ) : ?>
            <table class="box"><tr><td>
                <div class="box-cap">Amount in words</div>
                <div class="words"><?php echo $d['amount_words']; // Escaped in build_data(). ?></div>
            </td></tr></table>
        <?php endif; ?>

        <?php if ( 'yes' === $s['show_courier'] && ( $d['courier'] || $d['shipping_method'] || $d['tracking'] ) ) : ?>
            <table class="box"><tr><td>
                <div class="box-cap">Delivery</div>
                <?php if ( $d['shipping_method'] ) : ?><div>Method: <strong><?php echo esc_html( $d['shipping_method'] ); ?></strong></div><?php endif; ?>
                <?php if ( $d['courier'] ) : ?><div>Courier: <strong><?php echo esc_html( $d['courier'] ); ?></strong><?php echo $d['eta'] ? ' · ' . esc_html( $d['eta'] ) : ''; ?></div><?php endif; ?>
                <?php if ( $d['tracking'] ) : ?><div>Tracking: <strong><?php echo esc_html( $d['tracking'] ); ?></strong></div><?php endif; ?>
            </td></tr></table>
        <?php endif; ?>

        <?php if ( $d['customer_note'] ) : ?>
            <table class="box"><tr><td>
                <div class="box-cap">Customer note</div>
                <div><?php echo $d['customer_note']; // Escaped in build_data(). ?></div>
            </td></tr></table>
        <?php endif; ?>

        <?php if ( $d['invoice_notes'] ) : ?>
            <table class="box"><tr><td>
                <div class="box-cap">Notes</div>
                <div><?php echo $d['invoice_notes']; // Escaped in build_data(). ?></div>
            </td></tr></table>
        <?php endif; ?>
    </td>
    <td>
        <table class="totals">
            <?php foreach ( $d['totals'] as $row ) : ?>
                <?php if ( 'order_total' === $row['key'] ) : ?>
                    <tr class="grand"><td class="k">Total</td><td class="v"><?php echo esc_html( $d['order_total'] ); ?></td></tr>
                <?php else : ?>
                    <tr><td class="k"><?php echo esc_html( $row['label'] ); ?></td><td class="v"><?php echo esc_html( $row['value'] ); ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ( $d['refunded'] ) : ?>
                <tr class="grand"><td class="k">Net total</td><td class="v"><?php echo esc_html( $d['grand_total'] ); ?></td></tr>
            <?php endif; ?>

            <?php if ( $pay['advance'] > 0 ) : ?>
                <tr class="pay"><td class="k">Advance paid (verified)</td><td class="v">− <?php echo esc_html( $d['advance_text'] ); ?></td></tr>
            <?php elseif ( $pay['paid'] > 0 && ! $pay['is_paid'] ) : ?>
                <tr class="pay"><td class="k">Paid</td><td class="v">− <?php echo esc_html( $d['paid_text'] ); ?></td></tr>
            <?php endif; ?>

            <?php if ( ! $pay['is_paid'] ) : ?>
                <tr class="collect"><td class="k"><?php echo 'CASH ON DELIVERY' === $pay['label'] ? 'Collect on delivery' : 'Balance due'; ?></td><td class="v"><?php echo esc_html( $d['collect_text'] ); ?></td></tr>
            <?php endif; ?>
        </table>

        <?php if ( 'yes' === $s['show_status_stamp'] ) : ?>
            <?php if ( $pay['is_paid'] ) : ?>
                <table class="stamp"><tr><td>PAID</td></tr><tr><td class="sub"><?php echo esc_html( $d['payment_method'] ); ?></td></tr></table>
            <?php else : ?>
                <table class="stamp due"><tr><td><?php echo esc_html( $pay['label'] ); ?></td></tr><tr><td class="sub"><?php echo $pay['advance_pending'] ? 'Advance payment awaiting verification' : 'Amount to collect: ' . esc_html( $d['collect_text'] ); ?></td></tr></table>
            <?php endif; ?>
        <?php endif; ?>
    </td>
</tr></table>

<?php if ( $s['terms'] ) : ?>
    <div class="terms"><strong>Terms &amp; Conditions:</strong> <?php echo esc_html( $s['terms'] ); ?></div>
<?php endif; ?>

<?php if ( 'yes' === $s['show_signature'] ) : ?>
    <table class="sign"><tr>
        <td class="sig">Customer Signature</td>
        <td class="gap"></td>
        <td class="sig right"><?php echo esc_html( $s['signature_label'] ); ?></td>
    </tr></table>
<?php endif; ?>

<?php do_action( 'rar_wow_document_after', $d ); ?>
