<?php
/**
 * Sent to the buyer when an order is placed, with the PI attached.
 * Variables: $order (presented, buyer's view with bank), $links, $contact, $e (escaper).
 */

use App\Core\Format;

$amounts = $order['amounts'];
$bank = $order['bank'];
$whatsapp = preg_replace('/\D/', '', (string) ($contact['whatsapp'] ?? '923104489288'));
$row = static fn (string $label, ?string $value): string => $value === null || $value === ''
    ? ''
    : '<tr><td style="padding:3px 12px 3px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($label) . '</td><td style="padding:3px 0;font-size:14px;"><strong>' . htmlspecialchars($value) . '</strong></td></tr>';
?>
<h1 style="margin:0 0 12px;font-size:20px;">Thank you for your order, <?= $e($order['customer']['contact_name']) ?>.</h1>
<p style="margin:0 0 6px;">Order number: <strong style="font-family:monospace;font-size:16px;"><?= $e($order['order_no']) ?></strong></p>
<p style="margin:0 0 20px;color:#5c6170;font-size:14px;">Your proforma invoice is attached (PDF). <?= $order['buyer_reference'] ? 'Your reference: ' . $e($order['buyer_reference']) . '.' : '' ?></p>

<h2 style="margin:0 0 10px;font-size:16px;">1. Pay the deposit by bank transfer</h2>
<p style="margin:0 0 12px;">Amount: <strong style="font-size:18px;"><?= $e(Format::money($amounts['due_now_cents'])) ?></strong> (<?= (int) $amounts['deposit_percent'] ?>% of the goods). Please write <strong><?= $e($order['order_no']) ?></strong> as the payment reference.</p>
<?php if ($bank !== null): ?>
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;padding:14px 16px;background:#fbfaf7;border:1px dashed #e6e0d4;border-radius:12px;width:100%;">
    <tr><td>
      <table role="presentation" cellpadding="0" cellspacing="0">
        <?= $row('Beneficiary', $bank['beneficiary'] ?? null) ?>
        <?= $row('Bank', $bank['bank_name'] ?? null) ?>
        <?= $row('Branch', $bank['branch'] ?? null) ?>
        <?= $row('Account number', $bank['account_number'] ?? null) ?>
        <?= $row('IBAN', $bank['iban'] ?? null) ?>
        <?= $row('SWIFT / BIC', $bank['swift'] ?? null) ?>
        <?= $row('Bank address', $bank['bank_address'] ?? null) ?>
      </table>
      <?php if (!empty($bank['notes'])): ?><p style="margin:8px 0 0;font-size:13px;color:#5c6170;"><?= $e($bank['notes']) ?></p><?php endif; ?>
    </td></tr>
  </table>
<?php else: ?>
  <p style="margin:0 0 20px;padding:12px 14px;background:#fff4ea;border-radius:10px;font-size:14px;">Our team will send you the bank details for the transfer shortly.</p>
<?php endif; ?>

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
