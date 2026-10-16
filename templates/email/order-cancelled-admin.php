<?php
/**
 * Staff notification: the buyer cancelled an unpaid order.
 * Variables: $order (presented), $links, $e (escaper).
 */

use App\Core\Format;
?>
<h1 style="margin:0 0 12px;font-size:20px;">Order <?= $e($order['order_no']) ?> was cancelled</h1>
<p style="margin:0 0 14px;"><strong><?= $e($order['customer']['company_name']) ?></strong> cancelled this order before paying. It was <?= (int) $order['total_pcs'] ?> pcs, <?= $e(Format::money($order['amounts']['total_cents'])) ?>.</p>
<?php if ($order['cancel_reason']): ?>
  <p style="margin:0 0 6px;color:#5c6170;font-size:13px;">Reason given:</p>
  <p style="margin:0 0 18px;padding:12px 14px;background:#fbfaf7;border-radius:10px;font-size:14px;white-space:pre-line;"><?= $e($order['cancel_reason']) ?></p>
<?php endif; ?>
<p style="margin:0;">
  <a href="<?= $e($links['admin']) ?>" style="display:inline-block;background:#0b132b;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Open in the admin</a>
</p>
