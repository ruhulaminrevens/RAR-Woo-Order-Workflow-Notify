<?php
/**
 * Courier delivery label template (mPDF) — default 100×150 mm (4×6 in) thermal label.
 *
 * Override: copy to yourtheme/rar-wow/documents/label.php
 *
 * @var array $d Document data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$s     = $d['settings'];
$pay   = $d['pay'];
$phone = $d['shipping_phone'] ? $d['shipping_phone'] : $d['order']->get_billing_phone();
?>
<div class="lbl">
    <table class="lbl-head"><tr>
        <td class="lbl-shop"><?php echo esc_html( $d['shop']['name'] ); ?></td>
        <td class="lbl-title"><?php echo esc_html( $d['title'] ); ?><br>#<?php echo esc_html( $d['order_number'] ); ?></td>
    </tr></table>

    <div class="to-cap">DELIVER TO</div>
    <div class="to-name"><?php echo esc_html( $d['recipient_name'] ); ?></div>
    <?php if ( $phone ) : ?><div class="to-phone">&#9742; <?php echo esc_html( $phone ); ?></div><?php endif; ?>
    <div class="to-addr"><?php echo esc_html( $d['address_compact'] ); ?></div>

    <div class="cod">
        <?php if ( $pay['is_paid'] ) : ?>
            <div class="cod-cap">PREPAID</div>
            <div class="cod-amt">DO NOT COLLECT</div>
        <?php else : ?>
            <div class="cod-cap">CASH TO COLLECT (COD)</div>
            <div class="cod-amt"><?php echo esc_html( $d['collect_text'] ); ?></div>
        <?php endif; ?>
    </div>

    <table class="grid" style="margin-top:4pt;">
        <tr><td class="k">Courier</td><td><?php echo esc_html( $d['courier'] ? $d['courier'] : ( $d['shipping_method'] ? $d['shipping_method'] : '—' ) ); ?></td></tr>
        <?php if ( $d['tracking'] ) : ?><tr><td class="k">Tracking</td><td><strong><?php echo esc_html( $d['tracking'] ); ?></strong></td></tr><?php endif; ?>
        <tr><td class="k">Items</td><td><?php echo esc_html( $d['total_qty'] ); ?> pcs<?php echo $d['total_weight'] ? ' · ' . esc_html( $d['total_weight'] ) : ''; ?></td></tr>
        <?php
        $contents = array();
        foreach ( array_slice( $d['items'], 0, 6 ) as $item ) {
            $contents[] = $item['name'] . ' ×' . $item['qty'];
        }
        $more = count( $d['items'] ) - count( $contents );
        ?>
        <tr><td class="k">Contents</td><td style="font-size:8pt;"><?php echo esc_html( implode( ', ', $contents ) . ( $more > 0 ? ' +' . $more . ' more' : '' ) ); ?></td></tr>
        <tr><td class="k">Order date</td><td><?php echo esc_html( $d['order_date'] ); ?><?php echo $d['invoice_number'] ? ' · Inv ' . esc_html( $d['invoice_number'] ) : ''; ?></td></tr>
    </table>

    <table style="margin-top:4pt;"><tr>
        <td style="vertical-align:middle;">
            <?php if ( $d['barcode'] ) : ?>
                <barcode code="<?php echo esc_attr( $d['tracking'] ? preg_replace( '/[^A-Za-z0-9\-]/', '', $d['tracking'] ) : $d['barcode'] ); ?>" type="C128B" size="1.05" height="1.3" />
                <div class="small center"><?php echo esc_html( $d['tracking'] ? $d['tracking'] : '#' . $d['order_number'] ); ?></div>
            <?php endif; ?>
        </td>
        <?php if ( $d['qr'] ) : ?>
            <td style="width:24mm;text-align:right;vertical-align:middle;"><barcode code="<?php echo esc_attr( $d['qr'] ); ?>" type="QR" size="0.7" error="M" disableborder="1" /></td>
        <?php endif; ?>
    </tr></table>

    <?php if ( $d['customer_note'] ) : ?>
        <div class="note"><strong>Note:</strong> <?php echo $d['customer_note']; // Escaped in build_data(). ?></div>
    <?php endif; ?>

    <div class="from" style="margin-top:4pt;border-top:0.6pt solid #555;padding-top:2pt;">
        <strong>FROM:</strong> <?php echo esc_html( $d['shop']['name'] ); ?><?php echo $d['shop']['phone'] ? ' · ' . esc_html( $d['shop']['phone'] ) : ''; ?><br>
        <?php echo esc_html( $d['shop']['address_plain'] ); ?>
    </div>
</div>
