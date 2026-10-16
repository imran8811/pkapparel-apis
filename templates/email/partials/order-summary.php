<?php
/**
 * Order lines and totals, included by the order emails.
 * Variables: $order (presented order), $e (escaper).
 */

use App\Core\Format;

$amounts = $order['amounts'];
$cell = 'padding:8px 0;border-bottom:1px solid #eee8dc;font-size:14px;vertical-align:top;';
$num = $cell . 'text-align:right;white-space:nowrap;';
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-collapse:collapse;">
  <?php foreach ($order['lines'] as $line): ?>
    <tr>
      <td style="<?= $cell ?>">
        <strong><?= $e($line['style_name']) ?></strong><?= $line['style_no'] ? ' <span style="color:#5c6170;">' . $e($line['style_no']) . '</span>' : '' ?><br>
        <span style="color:#5c6170;font-size:13px;"><?= $e($line['color_name']) ?> (<?= $e($line['color_code']) ?>) · <?= $e(Format::sizes($line['size_qty'])) ?></span>
      </td>
      <td style="<?= $num ?>"><?= (int) $line['qty'] ?> pcs × <?= $e(Format::money($line['unit_price_cents'])) ?></td>
      <td style="<?= $num ?>padding-left:12px;"><?= $e(Format::money($line['line_cents'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php foreach ($order['addons'] as $addon): ?>
    <tr>
      <td style="<?= $cell ?>" colspan="2"><?= $e($addon['name']) ?></td>
      <td style="<?= $num ?>"><?= $addon['line_cents'] === null ? 'Price on request' : $e(Format::money($addon['line_cents'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <tr>
    <td style="<?= $cell ?>" colspan="2">Goods<?= $amounts['addons_cents'] > 0 ? ' and add-ons' : '' ?> (<?= (int) $order['total_pcs'] ?> pcs)</td>
    <td style="<?= $num ?>"><?= $e(Format::money($amounts['goods_cents'] + $amounts['addons_cents'])) ?></td>
  </tr>
  <tr>
    <td style="<?= $cell ?>" colspan="2">Freight, <?= $e(strtolower($order['shipping_mode_label'])) ?></td>
    <td style="<?= $num ?>"><?php
        echo match ($amounts['freight_status']) {
            'none' => 'Booked by you',
            'quoted' => 'We will quote it',
            'estimate' => $e(Format::money((int) $amounts['freight_cents'])) . ' (estimate)',
            default => $e(Format::money((int) $amounts['freight_cents'])),
        };
    ?></td>
  </tr>
  <tr>
    <td style="padding:10px 0 4px;font-size:15px;" colspan="2"><strong>Total</strong></td>
    <td style="padding:10px 0 4px;font-size:15px;text-align:right;white-space:nowrap;"><strong><?= $e(Format::money($amounts['total_cents'])) ?></strong></td>
  </tr>
  <tr>
    <td style="padding:2px 0;font-size:13px;color:#5c6170;" colspan="2"><?= (int) $amounts['deposit_percent'] ?>% deposit, to start production</td>
    <td style="padding:2px 0;font-size:13px;color:#5c6170;text-align:right;white-space:nowrap;"><?= $e(Format::money($amounts['deposit_cents'])) ?></td>
  </tr>
  <tr>
    <td style="padding:2px 0;font-size:13px;color:#5c6170;" colspan="2">Balance, before shipment<?= $amounts['freight_status'] === 'estimate' || $amounts['freight_status'] === 'quoted' ? ', with the final freight' : '' ?></td>
    <td style="padding:2px 0;font-size:13px;color:#5c6170;text-align:right;white-space:nowrap;"><?= $e(Format::money($amounts['balance_cents'])) ?></td>
  </tr>
</table>
