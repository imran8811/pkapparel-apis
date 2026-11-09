<?php
/**
 * A staff notification about a stock order. Variables: $order (staff view), $title, $intro, $facts (label => value),
 * $lines (bool: list the order's lines), $links (admin), $e (escaper).
 */

use App\Core\Format;

$facts = $facts ?? [];
$currency = (string) ($order['currency'] ?? 'PKR');
?>
<h1 style="margin:0 0 12px;font-size:20px;"><?= $e($title) ?></h1>
<p style="margin:0 0 6px;font-size:13px;color:#5c6170;">Order <strong style="font-family:monospace;color:#070c1f;"><?= $e($order['order_no']) ?></strong></p>
<p style="margin:0 0 18px;"><?= $e($intro) ?></p>

<?php if ($facts !== []): ?>
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
    <?php foreach ($facts as $label => $value): ?>
      <?php if ($value !== null && $value !== ''): ?>
        <tr>
          <td style="padding:4px 14px 4px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;"><?= $e($label) ?></td>
          <td style="padding:4px 0;font-size:14px;"><strong><?= $e($value) ?></strong></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php if (!empty($lines)): ?>
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 20px;border-top:1px solid #e6e0d4;">
    <?php foreach ($order['lines'] as $line): ?>
      <tr>
        <td style="padding:8px 0;font-size:14px;border-bottom:1px solid #f0ebe1;">
          <?= $e($line['style_name']) ?>, <?= $e($line['color_name']) ?> <span style="font-family:monospace;color:#5c6170;"><?= $e($line['color_code']) ?></span>
        </td>
        <td style="padding:8px 0 8px 12px;font-size:14px;text-align:right;white-space:nowrap;border-bottom:1px solid #f0ebe1;">
          <?= (int) ($line['sets'] ?? 0) ?> × <?= $e(Format::money((int) $line['unit_price_cents'], $currency)) ?>
          <?php if ((int) ($line['sets'] ?? 0) > 0 && (int) $line['qty'] > 0): $perSet = intdiv((int) $line['qty'], (int) $line['sets']); ?>
            <br><span style="color:#5c6170;font-size:12px;"><?= $perSet ?> pcs × <?= $e(Format::money((int) round((int) $line['unit_price_cents'] / max(1, $perSet)), $currency)) ?></span>
          <?php endif; ?>
        </td>
        <td style="padding:8px 0 8px 12px;font-size:14px;text-align:right;white-space:nowrap;border-bottom:1px solid #f0ebe1;"><?= $e(Format::money((int) $line['line_cents'], $currency)) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<p style="margin:4px 0 0;">
  <a href="<?= $e($links['admin']) ?>" style="display:inline-block;background:#0b132b;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Open in the admin</a>
</p>
