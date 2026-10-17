<?php
/**
 * Proforma invoice, or the balance invoice issued when an order is ready to ship (A4, rendered by dompdf:
 * tables for layout, no flexbox or grid; DejaVu Sans for full Unicode).
 * Variables: $order (presented order), $document (kind: proforma|balance, number, date), $bank (array|null),
 * $company, $contact, $addresses (settings), $logo (data URI), $e (escaper).
 */

use App\Core\Format;

$amounts = $order['amounts'];
$isBalance = ($document['kind'] ?? 'proforma') === 'balance';
$received = array_values(array_filter($order['payments'] ?? [], static fn (array $p): bool => $p['status'] === 'confirmed'));
$customer = $order['customer'];
$address = $order['address'];
$factory = is_array($addresses) && isset($addresses[0]) ? $addresses[0] : null;
$sellerName = trim((string) ($company['legal_name'] ?? '')) !== '' ? $company['legal_name'] : ($company['name'] ?? 'PK Apparel');
$freightLabel = match ($amounts['freight_status']) {
    'none' => 'Booked by the buyer',
    'quoted' => 'To be quoted',
    'estimate' => Format::money((int) $amounts['freight_cents']) . ' (estimate)',
    default => Format::money((int) $amounts['freight_cents']),
};
$addressLines = static fn (array $a): array => array_values(array_filter([
    $a['line1'] ?? null,
    $a['line2'] ?? null,
    trim(implode(' ', array_filter([$a['postal_code'] ?? null, $a['city'] ?? null]))) . (empty($a['state']) ? '' : ', ' . $a['state']),
    Format::country($a['country_code'] ?? null),
]));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $isBalance ? 'Invoice ' . $e($document['number']) : 'Proforma invoice ' . $e($order['order_no']) ?></title>
<style>
  @page { margin: 34px 38px 46px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #0b132b; line-height: 1.45; }
  h1 { font-size: 19px; margin: 0; letter-spacing: 1px; color: #0048ac; }
  h2 { font-size: 8.5px; margin: 0 0 4px; letter-spacing: 1.2px; text-transform: uppercase; color: #5c6170; font-weight: bold; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; }
  .muted { color: #5c6170; }
  .mono { font-family: 'DejaVu Sans Mono', monospace; }
  .rule { border-top: 2px solid #0048ac; margin: 12px 0 14px; }
  .box { border: 1px solid #e3ddd0; border-radius: 6px; padding: 8px 10px; }
  .lines th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .8px; color: #5c6170; border-bottom: 1px solid #c9c2b4; padding: 5px 6px; }
  .lines td { border-bottom: 1px solid #ebe6dc; padding: 6px; }
  .num { text-align: right; white-space: nowrap; }
  .totals td { padding: 3px 0; }
  .totals .grand td { border-top: 1px solid #0b132b; padding-top: 6px; font-size: 11px; font-weight: bold; }
  .due { background: #fff4ea; border: 1px solid #f3c79f; border-radius: 6px; padding: 8px 10px; }
  .terms li { margin-bottom: 2px; }
  .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5px; color: #8a8f9c; text-align: center; }
</style>
</head>
<body>
<div class="footer">
  <?= $e($sellerName) ?><?= $factory ? ' · ' . $e($factory['line'] . ', ' . $factory['city'] . ', ' . $factory['country']) : '' ?>
  <?= !empty($contact['email']) ? ' · ' . $e($contact['email']) : '' ?><?= !empty($contact['phone']) ? ' · ' . $e($contact['phone']) : '' ?>
</div>

<table>
  <tr>
    <td style="width: 55%;">
      <img src="<?= $logo ?>" style="height: 56px;" alt="PK Apparel">
    </td>
    <td style="text-align: right;">
      <h1><?= $isBalance ? 'INVOICE' : 'PROFORMA INVOICE' ?></h1>
      <table style="margin-top: 6px;">
        <?php if ($isBalance): ?>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">Invoice no.</td><td class="mono" style="width: 1%; white-space: nowrap; font-weight: bold;"><?= $e($document['number']) ?></td></tr>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">Order no.</td><td class="mono" style="white-space: nowrap;"><?= $e($order['order_no']) ?></td></tr>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">Date</td><td style="white-space: nowrap;"><?= $e(Format::date($document['date'])) ?></td></tr>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">Due</td><td style="white-space: nowrap;">Before shipment</td></tr>
        <?php else: ?>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">PI / order no.</td><td class="mono" style="width: 1%; white-space: nowrap; font-weight: bold;"><?= $e($order['order_no']) ?></td></tr>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">Date</td><td style="white-space: nowrap;"><?= $e(Format::date($order['placed_at'])) ?></td></tr>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">Valid until</td><td style="white-space: nowrap;"><?= $e(Format::date($order['valid_until'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($order['buyer_reference']): ?>
          <tr><td class="muted" style="text-align: right; padding-right: 8px;">Your reference</td><td style="white-space: nowrap;"><?= $e($order['buyer_reference']) ?></td></tr>
        <?php endif; ?>
      </table>
    </td>
  </tr>
</table>

<div class="rule"></div>

<table>
  <tr>
    <td style="width: 33%; padding-right: 10px;">
      <h2>Seller</h2>
      <strong><?= $e($sellerName) ?></strong><br>
      <?php if ($factory): ?><?= $e($factory['line']) ?><br><?= $e($factory['city'] . ', ' . $factory['country']) ?><br><?php endif; ?>
      <?php if (!empty($company['ntn'])): ?>NTN <?= $e($company['ntn']) ?><br><?php endif; ?>
      <?= $e($contact['email'] ?? '') ?><br><?= $e($contact['phone'] ?? '') ?>
    </td>
    <td style="width: 33%; padding-right: 10px;">
      <h2>Buyer</h2>
      <strong><?= $e($customer['company_name']) ?></strong><br>
      <?= $e($customer['contact_name']) ?><br>
      <?= $e($customer['email']) ?><br>
      <?= $e($customer['phone']) ?><br>
      <?= $e(Format::country($customer['country_code'])) ?>
    </td>
    <td style="width: 34%;">
      <h2>Consignee / deliver to</h2>
      <strong><?= $e($address['company']) ?></strong><br>
      <?= $e($address['contact_name']) ?>, <?= $e($address['phone']) ?><br>
      <?php foreach ($addressLines($address) as $line): ?><?= $e($line) ?><br><?php endforeach; ?>
      <?php if (!empty($address['tax_id'])): ?>Tax / import ID: <?= $e($address['tax_id']) ?><?php endif; ?>
    </td>
  </tr>
</table>

<table style="margin-top: 12px;">
  <tr>
    <td class="box" style="width: 25%;"><h2>Shipping</h2><?= $e($order['shipping_mode_label']) ?><?php if ($order['forwarder'] && $order['forwarder']['name']): ?><br><span class="muted"><?= $e($order['forwarder']['name']) ?></span><?php endif; ?></td>
    <td style="width: 8px;"></td>
    <td class="box" style="width: 25%;"><h2>Terms</h2><?= $e($order['incoterm']) ?> · USD</td>
    <td style="width: 8px;"></td>
    <td class="box" style="width: 25%;"><h2>Payment</h2><?= (int) $amounts['deposit_percent'] ?>% deposit, balance before shipment, by bank transfer</td>
    <td style="width: 8px;"></td>
    <td class="box"><h2>Production</h2>About <?= (int) $order['lead_time_days'] ?> days after the deposit is confirmed</td>
  </tr>
</table>

<table class="lines" style="margin-top: 14px;">
  <thead>
    <tr>
      <th style="width: 4%;">#</th>
      <th style="width: 36%;">Style and color</th>
      <th style="width: 30%;">Sizes × pieces</th>
      <th class="num" style="width: 8%;">Qty</th>
      <th class="num" style="width: 10%;">Price / pc</th>
      <th class="num" style="width: 12%;">Amount</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($order['lines'] as $index => $line): ?>
      <tr>
        <td class="muted"><?= $index + 1 ?></td>
        <td>
          <strong><?= $e($line['style_name']) ?></strong><?= $line['style_no'] ? ' <span class="muted mono">' . $e($line['style_no']) . '</span>' : '' ?><br>
          <?= $e($line['color_name']) ?> <span class="muted mono">(<?= $e($line['color_code']) ?>)</span>
        </td>
        <td><?= $e(Format::sizes($line['size_qty'])) ?></td>
        <td class="num"><?= number_format($line['qty']) ?></td>
        <td class="num"><?= $e(Format::money($line['unit_price_cents'])) ?></td>
        <td class="num"><?= $e(Format::money($line['line_cents'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($order['addons'] as $addon): ?>
      <tr>
        <td class="muted">+</td>
        <td colspan="2"><?= $e($addon['name']) ?></td>
        <td class="num"><?= $addon['pricing'] === 'per_piece' ? number_format($addon['qty']) : '' ?></td>
        <td class="num"><?= $addon['unit_price_cents'] === null ? '' : $e(Format::money($addon['unit_price_cents'])) ?></td>
        <td class="num"><?= $addon['line_cents'] === null ? 'On request' : $e(Format::money($addon['line_cents'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<table style="margin-top: 12px;">
  <tr>
    <td style="width: 52%; padding-right: 18px;">
      <?php $packing = $order['packing']; ?>
      <h2>Packing estimate</h2>
      <?= number_format($order['total_pcs']) ?> pcs in about <?= (int) $packing['cartons'] ?> cartons ·
      <?= $e(number_format($packing['gross_g'] / 1000, 1)) ?> kg gross · <?= $e(number_format((float) $packing['cbm'], 2)) ?> CBM
      <?php if ($order['notes']): ?>
        <h2 style="margin-top: 10px;">Buyer's notes</h2>
        <?= nl2br($e($order['notes'])) ?>
      <?php endif; ?>
    </td>
    <td>
      <table class="totals">
        <tr><td>Goods (<?= number_format($order['total_pcs']) ?> pcs)</td><td class="num"><?= $e(Format::money($amounts['goods_cents'])) ?></td></tr>
        <?php if ($order['addons'] !== []): ?>
          <tr><td>Add-ons<?= $amounts['has_quoted_addons'] ? ' (priced items)' : '' ?></td><td class="num"><?= $e(Format::money($amounts['addons_cents'])) ?></td></tr>
        <?php endif; ?>
        <tr><td>Freight</td><td class="num"><?= $e($freightLabel) ?></td></tr>
        <tr class="grand"><td>Total</td><td class="num"><?= $e(Format::money($amounts['total_cents'])) ?></td></tr>
        <?php if ($isBalance): ?>
          <?php foreach ($received as $payment): ?>
            <tr><td class="muted"><?= $e($payment['kind_label']) ?> received<?= $payment['receipt_no'] ? ' (' . $e($payment['receipt_no']) . ')' : '' ?></td><td class="num muted">−<?= $e(Format::money((int) ($payment['received_cents'] ?? $payment['amount_cents']))) ?></td></tr>
          <?php endforeach; ?>
          <tr class="grand"><td>Balance due</td><td class="num"><?= $e(Format::money(max(0, $amounts['total_cents'] - $amounts['paid_cents']))) ?></td></tr>
        <?php else: ?>
          <tr><td class="muted"><?= (int) $amounts['deposit_percent'] ?>% deposit (goods and add-ons)</td><td class="num muted"><?= $e(Format::money($amounts['deposit_cents'])) ?></td></tr>
          <tr><td class="muted">Balance before shipment</td><td class="num muted"><?= $e(Format::money($amounts['balance_cents'])) ?></td></tr>
        <?php endif; ?>
      </table>
    </td>
  </tr>
</table>

<?php if ($amounts['due_now_cents'] > 0): ?>
<table style="margin-top: 14px;">
  <tr>
    <td class="due">
      <h2 style="color: #9a4a12;">Amount due now: <?= $e(Format::money($amounts['due_now_cents'])) ?></h2>
      Please pay by bank transfer and write <strong class="mono"><?= $e($order['order_no']) ?></strong> as the payment reference.
      Then enter the transaction ID on the Confirm payment page of your order. Bank charges on the sending side are paid by the buyer.
      <?php if ($bank !== null): ?>
        <table style="margin-top: 6px;">
          <?php foreach ([
              'Beneficiary' => $bank['beneficiary'] ?? null,
              'Bank' => $bank['bank_name'] ?? null,
              'Branch' => $bank['branch'] ?? null,
              'Account number' => $bank['account_number'] ?? null,
              'IBAN' => $bank['iban'] ?? null,
              'SWIFT / BIC' => $bank['swift'] ?? null,
              'Bank address' => $bank['bank_address'] ?? null,
          ] as $label => $value): ?>
            <?php if ($value !== null && $value !== ''): ?>
              <tr><td class="muted" style="width: 22%; padding: 1px 0;"><?= $e($label) ?></td><td class="mono" style="padding: 1px 0;"><?= $e($value) ?></td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </table>
        <?php if (!empty($bank['notes'])): ?><div class="muted" style="margin-top: 4px;"><?= $e($bank['notes']) ?></div><?php endif; ?>
      <?php else: ?>
        <div style="margin-top: 4px;">Our team will send you the bank details for the transfer.</div>
      <?php endif; ?>
    </td>
  </tr>
</table>
<?php endif; ?>

<h2 style="margin-top: 14px;">Terms</h2>
<ul class="terms" style="margin: 0; padding-left: 14px;">
  <li>Prices are per piece in US dollars. Every order is made to order; production starts when the deposit is confirmed.</li>
  <li><?= match ($amounts['freight_status']) {
      'none' => 'The buyer books the freight; we hand the goods to the buyer\'s forwarder in Pakistan.',
      'final' => 'The freight shown is final. Import duties and taxes are paid by the buyer.',
      default => 'The freight is an estimate until confirmed. The final freight is invoiced with the balance. Import duties and taxes are paid by the buyer.',
  } ?></li>
  <?php if ($amounts['has_quoted_addons']): ?><li>Add-ons marked "On request" are quoted separately and invoiced with the balance.</li><?php endif; ?>
  <?php if ($isBalance): ?>
    <li>Goods ship after the full amount is received. Our terms of sale apply (pkapparel.com/terms-of-sale).</li>
  <?php else: ?>
    <li>Ready dates are estimates and may change with approvals, fabric availability or holidays. Goods ship after the full amount is received.</li>
    <li>Changes and cancellations are possible until the fabric is cut. Our terms of sale apply (pkapparel.com/terms-of-sale).</li>
  <?php endif; ?>
</ul>
</body>
</html>
