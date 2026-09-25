<?php
/**
 * Packing slip template (mPDF). No prices — for picking, packing and courier hand-over.
 *
 * Override: copy to yourtheme/rar-wow/documents/packing-slip.php
 *
 * @var array $d Document data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$s   = $d['settings'];
$pay = $d['pay'];
?>
<htmlpagefooter name="rarfooter">
    <table class="footer"><tr>
        <td><?php echo esc_html( $d['shop']['name'] ); ?> · Packing slip for order #<?php echo esc_html( $d['order_number'] ); ?></td>
        <td class="right">Printed <?php echo esc_html( $d['generated'] ); ?> · Page {PAGENO} of {nbpg}</td>
    </tr></table>
</htmlpagefooter>
<sethtmlpagefooter name="rarfooter" value="on" show-this-page="1" />

<?php do_action( 'rar_wow_document_before', $d ); ?>

<table class="head"><tr>
    <td style="width:55%;">
        <?php if ( $d['shop']['logo'] ) : ?>
            <div class="logo"><img src="<?php echo esc_attr( $d['shop']['logo'] ); ?>" alt=""></div>
        <?php else : ?>
            <div class="brand-name"><?php echo esc_html( $d['shop']['name'] ); ?></div>
        <?php endif; ?>
        <div class="shop-lines">
            <?php echo $d['shop']['address']; // Escaped in build_data(). ?>
            <?php if ( $d['shop']['phone'] ) : ?><br>Phone: <?php echo esc_html( $d['shop']['phone'] ); ?><?php endif; ?>
        </div>
    </td>
    <td style="width:45%;" class="right">
        <div class="doc-title"><?php echo esc_html( $d['title'] ); ?></div>
        <?php if ( $d['subtitle'] ) : ?><div class="doc-subtitle"><?php echo esc_html( $d['subtitle'] ); ?></div><?php endif; ?>
        <table class="meta" align="right">
            <tr><td class="k">Order No.</td><td class="v">#<?php echo esc_html( $d['order_number'] ); ?></td></tr>
            <tr><td class="k">Order Date</td><td class="v"><?php echo esc_html( $d['order_date'] ); ?></td></tr>
            <?php if ( $d['invoice_number'] ) : ?>
                <tr><td class="k">Invoice No.</td><td class="v"><?php echo esc_html( $d['invoice_number'] ); ?></td></tr>
            <?php endif; ?>
            <tr><td class="k">Status</td><td class="v"><?php echo esc_html( $d['order_status'] ); ?></td></tr>
        </table>
        <?php if ( $d['barcode'] ) : ?>
            <div style="text-align:right;padding-top:4pt;"><barcode code="<?php echo esc_attr( $d['barcode'] ); ?>" type="C128B" size="1" height="0.9" /></div>
        <?php endif; ?>
    </td>
</tr></table>

<div class="bar"></div>

<table class="parties"><tr>
    <td class="col ship-to-big" style="width:52%;">
        <div class="label-cap">Ship To</div>
        <div class="party"><?php echo $d['ship_to_html']; // Escaped in build_data(). ?></div>
        <?php $phone = $d['shipping_phone'] ? $d['shipping_phone'] : $d['order']->get_billing_phone(); ?>
        <?php if ( $phone ) : ?><div class="phone-big">&#9742; <?php echo esc_html( $phone ); ?></div><?php endif; ?>
    </td>
    <td class="col" style="width:48%;">
        <table class="box"><tr><td>
            <div class="box-cap">Delivery</div>
            <?php if ( $d['shipping_method'] ) : ?><div>Method: <strong><?php echo esc_html( $d['shipping_method'] ); ?></strong></div><?php endif; ?>
            <div>Courier: <strong><?php echo esc_html( $d['courier'] ? $d['courier'] : 'Not assigned' ); ?></strong></div>
            <?php if ( $d['tracking'] ) : ?><div>Tracking: <strong><?php echo esc_html( $d['tracking'] ); ?></strong></div><?php endif; ?>
            <?php if ( $d['total_weight'] && 'yes' === $s['show_weight'] ) : ?><div>Total weight: <strong><?php echo esc_html( $d['total_weight'] ); ?></strong></div><?php endif; ?>
        </td></tr></table>
        <?php if ( $pay['is_paid'] ) : ?>
            <table class="stamp"><tr><td>PREPAID — DO NOT COLLECT</td></tr><tr><td class="sub"><?php echo esc_html( $d['payment_method'] ); ?></td></tr></table>
        <?php else : ?>
            <table class="stamp due"><tr><td>COLLECT <?php echo esc_html( $d['collect_text'] ); ?></td></tr><tr><td class="sub"><?php echo $pay['advance'] > 0 ? 'Advance ' . esc_html( $d['advance_text'] ) . ' already paid' : esc_html( $pay['label'] ); ?></td></tr></table>
        <?php endif; ?>
    </td>
</tr></table>

<table class="items">
    <thead>
        <tr>
            <th class="check-cell" style="width:8mm;">&#10003;</th>
            <?php if ( 'yes' === $s['show_thumbnails'] ) : ?><th style="width:13mm;"></th><?php endif; ?>
            <th>Item</th>
            <?php if ( 'yes' === $s['show_sku'] ) : ?><th style="width:28mm;">SKU</th><?php endif; ?>
            <?php if ( 'yes' === $s['show_weight'] ) : ?><th class="num" style="width:22mm;">Weight</th><?php endif; ?>
            <th class="qty" style="width:14mm;">Qty</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $d['items'] as $i => $item ) : ?>
        <tr class="<?php echo 1 === $i % 2 ? 'alt' : ''; ?>">
            <td class="check-cell"><table style="width:4.4mm;"><tr><td class="check">&nbsp;</td></tr></table></td>
            <?php if ( 'yes' === $s['show_thumbnails'] ) : ?>
                <td><?php if ( $item['thumb'] ) : ?><img class="thumb" src="<?php echo esc_attr( $item['thumb'] ); ?>" alt="" style="width:11mm;height:11mm;"><?php endif; ?></td>
            <?php endif; ?>
            <td>
                <div class="item-name"><?php echo esc_html( $item['name'] ); ?></div>
                <?php if ( $item['meta'] ) : ?><div class="item-meta"><?php echo esc_html( $item['meta'] ); ?></div><?php endif; ?>
                <?php if ( $item['refunded'] ) : ?><div class="refunded">Refunded qty: <?php echo esc_html( $item['refunded'] ); ?> — do not pack</div><?php endif; ?>
            </td>
            <?php if ( 'yes' === $s['show_sku'] ) : ?><td class="muted"><?php echo esc_html( $item['sku'] ? $item['sku'] : '—' ); ?></td><?php endif; ?>
            <?php if ( 'yes' === $s['show_weight'] ) : ?><td class="num muted"><?php echo esc_html( $item['weight'] ? $item['weight'] : '—' ); ?></td><?php endif; ?>
            <td class="qty strong" style="font-size:11pt;"><?php echo esc_html( $item['qty'] ); ?></td>
        </tr>
    <?php endforeach; ?>
        <tr>
            <td colspan="<?php echo 2 + ( 'yes' === $s['show_thumbnails'] ? 1 : 0 ) + ( 'yes' === $s['show_sku'] ? 1 : 0 ) + ( 'yes' === $s['show_weight'] ? 1 : 0 ); ?>" class="right strong">Total items to pack</td>
            <td class="qty strong" style="font-size:11pt;"><?php echo esc_html( $d['total_qty'] ); ?></td>
        </tr>
    </tbody>
</table>

<?php if ( $d['customer_note'] ) : ?>
    <table class="box" style="margin-top:10pt;"><tr><td>
        <div class="box-cap">Customer note</div>
        <div><?php echo $d['customer_note']; // Escaped in build_data(). ?></div>
    </td></tr></table>
<?php endif; ?>

<table class="handover"><tr>
    <td class="sig">Packed by</td>
    <td class="gap"></td>
    <td class="sig">Checked by</td>
    <td class="gap"></td>
    <td class="sig">Courier received (sign &amp; date)</td>
</tr></table>

<?php do_action( 'rar_wow_document_after', $d ); ?>
