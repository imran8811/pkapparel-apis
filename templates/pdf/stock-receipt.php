<?php
/**
 * Payment receipt of a stock order (A4), emailed when an admin confirms the payment. In rupees, with the sets
 * bought, and without the country or tax numbers (owner decision 2026-11-09).
 * Variables: $order (presented order), $payment (presented payment, staff view), $company, $contact, $addresses, $logo, $e.
 */

use App\Core\Format;

$amounts = $order['amounts'];
$customer = $order['customer'];
$factory = is_array($addresses) && isset($addresses[0]) ? $addresses[0] : null;
$sellerName = trim((string) ($company['legal_name'] ?? '')) !== '' ? $company['legal_name'] : ($company['name'] ?? 'PK Apparel');
$currency = (string) ($order['currency'] ?? 'PKR');
$received = (int) ($payment['received_cents'] ?? $payment['amount_cents']);
$outstanding = max(0, $amounts['total_cents'] - $amounts['paid_cents']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt <?= $e($payment['receipt_no']) ?></title>
<style>
  @page { margin: 34px 38px 46px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0b132b; line-height: 1.5; }
  h1 { font-size: 20px; margin: 0; letter-spacing: 1px; color: #0048ac; }
  h2 { font-size: 8.5px; margin: 0 0 4px; letter-spacing: 1.2px; text-transform: uppercase; color: #5c6170; font-weight: bold; }
  table { border-collapse: collapse; width: 100%; }
  td { vertical-align: top; }
  .muted { color: #5c6170; }
  .mono { font-family: 'DejaVu Sans Mono', monospace; }
  .rule { border-top: 2px solid #0048ac; margin: 12px 0 16px; }
  .amount { border: 1px solid #b7e4c7; background: #effaf3; border-radius: 6px; padding: 12px 14px; }
  .rows td { padding: 4px 0; border-bottom: 1px solid #ebe6dc; }
  .lines th { text-align: left; font-size: 8.5px; letter-spacing: 1px; text-transform: uppercase; color: #5c6170; padding: 4px 0; border-bottom: 1px solid #0b132b; }
  .lines td { padding: 5px 0; border-bottom: 1px solid #ebe6dc; }
  .num { text-align: right; white-space: nowrap; }
  .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5px; color: #8a8f9c; text-align: center; }
</style>
</head>
<body>
<div class="footer">
  <?= $e($sellerName) ?><?= $factory ? ' · ' . $e($factory['line'] . ', ' . $factory['city']) : '' ?>
  <?= !empty($contact['email']) ? ' · ' . $e($contact['email']) : '' ?><?= !empty($contact['phone']) ? ' · ' . $e($contact['phone']) : '' ?>
</div>

<table>
  <tr>
    <td style="width: 55%;"><img src="<?= $logo ?>" style="height: 56px;" alt="PK Apparel"></td>
    <td style="text-align: right;">
      <h1>PAYMENT RECEIPT</h1>
      <table style="margin-top: 6px;">
        <tr><td class="muted" style="text-align: right; padding-right: 8px;">Receipt no.</td><td class="mono" style="width: 1%; white-space: nowrap; font-weight: bold;"><?= $e($payment['receipt_no']) ?></td></tr>
        <tr><td class="muted" style="text-align: right; padding-right: 8px;">Date</td><td style="white-space: nowrap;"><?= $e(Format::date($payment['reviewed_at'] ?? null)) ?></td></tr>
        <tr><td class="muted" style="text-align: right; padding-right: 8px;">Order no.</td><td class="mono" style="white-space: nowrap;"><?= $e($order['order_no']) ?></td></tr>
      </table>
    </td>
  </tr>
</table>

<div class="rule"></div>

<table>
  <tr>
    <td style="width: 50%; padding-right: 12px;">
      <h2>Received from</h2>
      <strong><?= $e($customer['company_name']) ?></strong><br>
      <?= $e($customer['contact_name']) ?><br>
      <?= $e($customer['phone']) ?><br>
      <?php if (!empty($customer['city'])): ?><?= $e($customer['city']) ?><?php endif; ?>
    </td>
    <td>
      <h2>Received by</h2>
      <strong><?= $e($sellerName) ?></strong><br>
      <?php if ($factory): ?><?= $e($factory['line']) ?><br><?= $e($factory['city']) ?><?php endif; ?>
    </td>
  </tr>
</table>

<table style="margin-top: 16px;">
  <tr>
    <td class="amount">
      <h2 style="color: #1b6b3a;">Amount received</h2>
      <span style="font-size: 18px; font-weight: bold;"><?= $e(Format::money($received, $currency)) ?></span>
      <span class="muted"> · Payment for order <?= $e($order['order_no']) ?></span>
    </td>
  </tr>
</table>

<table class="rows" style="margin-top: 14px;">
  <tr><td class="muted" style="width: 35%;">Transaction ID</td><td class="mono"><?= $e($payment['transaction_id']) ?></td></tr>
  <tr><td class="muted">Sent on</td><td><?= $e(Format::date($payment['transferred_on'])) ?> by <?= $e($payment['payer_name']) ?>, <?= $e($payment['payer_bank']) ?></td></tr>
  <?php if (!empty($payment['received_on'])): ?>
    <tr><td class="muted">Received on</td><td><?= $e(Format::date($payment['received_on'])) ?></td></tr>
  <?php endif; ?>
  <tr><td class="muted">Order total</td><td><?= $e(Format::money($amounts['total_cents'], $currency)) ?></td></tr>
  <tr><td class="muted">Paid so far</td><td><?= $e(Format::money($amounts['paid_cents'], $currency)) ?></td></tr>
  <tr><td class="muted">Still to pay</td><td><strong><?= $e(Format::money($outstanding, $currency)) ?></strong></td></tr>
</table>

<table class="lines" style="margin-top: 18px;">
  <tr><th>Jeans</th><th class="num">Sets</th><th class="num">Price per set</th><th class="num">Amount</th></tr>
  <?php foreach ($order['lines'] as $line): ?>
    <tr>
      <td><?= $e($line['style_name']) ?>, <?= $e($line['color_name']) ?> <span class="mono muted"><?= $e($line['color_code']) ?></span></td>
      <td class="num"><?= (int) ($line['sets'] ?? 0) ?></td>
      <td class="num"><?= $e(Format::money((int) $line['unit_price_cents'], $currency)) ?></td>
      <td class="num"><?= $e(Format::money((int) $line['line_cents'], $currency)) ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<p class="muted" style="margin-top: 18px;">Thank you for your payment. This receipt confirms that the amount above has reached our account. Keep it with your order records. If there is an issue with your order, tell us within 7 days of receiving it.</p>
</body>
</html>
