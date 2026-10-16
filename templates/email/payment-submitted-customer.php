<?php
/**
 * Acknowledgement to the buyer after they report a transfer.
 * Variables: $order (presented), $payment (presented), $links, $e (escaper).
 */

use App\Core\Format;
?>
<h1 style="margin:0 0 12px;font-size:20px;">We received your payment details</h1>
<p style="margin:0 0 14px;">Thank you, <?= $e($order['customer']['contact_name']) ?>. You reported a transfer of <strong><?= $e(Format::money($payment['amount_cents'])) ?></strong> for order <strong><?= $e($order['order_no']) ?></strong> (transaction <?= $e($payment['transaction_id']) ?>, sent on <?= $e(Format::date($payment['transferred_on'])) ?>).</p>
<p style="margin:0 0 14px;">We are checking it against our bank statement. International transfers usually arrive within 1 to 3 working days. As soon as the money is in, we confirm the payment and email you a receipt<?= $payment['kind'] === 'deposit' ? ', and production of your order starts' : '' ?>.</p>
<p style="margin:0 0 22px;">
  <a href="<?= $e($links['order']) ?>" style="display:inline-block;background:#e57a2e;color:#070c1f;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">View your order</a>
</p>
<p style="margin:0;font-size:13px;color:#5c6170;">Made a mistake in the details? Reply to this email and we will correct it.</p>
