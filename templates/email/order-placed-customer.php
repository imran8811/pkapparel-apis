<?php
/**
 * Sent to the buyer when an order is placed, with the PI attached.
 * Variables: $order (presented, buyer's view with bank), $links, $contact, $e (escaper).
 */

use App\Core\Format;

$amounts = $order['amounts'];
$bank = $order['bank'];
$whatsapp = preg_replace('/\D/', '', (string) ($contact['whatsapp'] ?? '923104489288'));
?>
<h1 style="margin:0 0 12px;font-size:20px;">Thank you for your order, <?= $e($order['customer']['contact_name']) ?>.</h1>
<p style="margin:0 0 6px;">Order number: <strong style="font-family:monospace;font-size:16px;"><?= $e($order['order_no']) ?></strong></p>
<p style="margin:0 0 20px;color:#5c6170;font-size:14px;">Your proforma invoice is attached (PDF). <?= $order['buyer_reference'] ? 'Your reference: ' . $e($order['buyer_reference']) . '.' : '' ?></p>

<h2 style="margin:0 0 10px;font-size:16px;">1. Pay the deposit by bank transfer</h2>
<p style="margin:0 0 12px;">Amount: <strong style="font-size:18px;"><?= $e(Format::money($amounts['due_now_cents'])) ?></strong> (<?= (int) $amounts['deposit_percent'] ?>% of the goods). Please write <strong><?= $e($order['order_no']) ?></strong> as the payment reference.</p>
<?php require __DIR__ . '/partials/bank-details.php'; ?>

<h2 style="margin:0 0 10px;font-size:16px;">2. Tell us about your transfer</h2>
<p style="margin:0 0 14px;">After you send the money, enter the transaction ID on the Confirm payment page. We check it against our bank statement and email you a receipt.</p>
<p style="margin:0 0 22px;">
  <a href="<?= $e($links['confirm_payment']) ?>" style="display:inline-block;background:#e57a2e;color:#070c1f;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Confirm payment</a>
</p>

<h2 style="margin:0 0 10px;font-size:16px;">3. Production starts</h2>
<p style="margin:0 0 22px;">Your order is ready about <?= (int) $order['lead_time_days'] ?> days after the deposit is confirmed. The balance<?= $amounts['freight_status'] === 'estimate' || $amounts['freight_status'] === 'quoted' ? ', with the final freight,' : '' ?> is due before shipment.</p>

<h2 style="margin:0 0 10px;font-size:16px;">Your order</h2>
<?php require __DIR__ . '/partials/order-summary.php'; ?>

<p style="margin:0 0 16px;font-size:14px;">
  <a href="<?= $e($links['order']) ?>" style="color:#0048ac;">View your order online</a> · Questions? <a href="https://wa.me/<?= $e($whatsapp) ?>" style="color:#0048ac;">WhatsApp us</a> or reply to this email.
</p>
