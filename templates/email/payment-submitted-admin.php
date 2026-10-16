<?php
/**
 * Staff notification: a buyer reported a bank transfer.
 * Variables: $order (presented), $payment (presented, staff view), $links, $e (escaper).
 */

use App\Core\Format;

$row = static fn (string $label, ?string $value): string => $value === null || $value === ''
    ? ''
    : '<tr><td style="padding:4px 14px 4px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($label) . '</td><td style="padding:4px 0;font-size:14px;">' . nl2br(htmlspecialchars($value)) . '</td></tr>';
?>
<h1 style="margin:0 0 12px;font-size:20px;">Payment to check for <?= $e($order['order_no']) ?></h1>
<p style="margin:0 0 16px;"><strong><?= $e($order['customer']['company_name']) ?></strong> reported a bank transfer. Please check it against the bank statement before confirming it.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
  <?= $row('Payment', $payment['kind_label']) ?>
  <?= $row('Amount', Format::money($payment['amount_cents'])) ?>
  <?= $row('Amount due', Format::money($order['amounts']['due_now_cents'])) ?>
  <?= $row('Transaction ID', $payment['transaction_id']) ?>
  <?= $row('Transfer date', Format::date($payment['transferred_on'])) ?>
  <?= $row('Sent by', $payment['payer_name']) ?>
  <?= $row('From bank', $payment['payer_bank']) ?>
  <?= $row('Slip', $payment['has_slip'] ? 'Attached in the admin' : 'No slip') ?>
  <?= $row('Notes', $payment['notes']) ?>
</table>
<p style="margin:0;">
  <a href="<?= $e($links['admin']) ?>" style="display:inline-block;background:#0b132b;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Open the order</a>
</p>
