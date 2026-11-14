<?php
/**
 * An invoice made in Admin → Invoices: sample invoice, proforma invoice (production deposit) or final invoice.
 * A4, rendered by dompdf: tables for layout (no flexbox or grid), DejaVu Sans for full Unicode (€, £, —).
 * Variables: $invoice (Invoices::present), $bank (array|null), $company, $contact, $addresses (settings),
 * $website ("www.pkapparel.com"), $pages (page count, for the footer), $logo (data URI), $e (escaper).
 */

use App\Core\Format;

$amounts = $invoice['amounts'];
$currency = $invoice['currency'];
$money = static fn (int $cents): string => Format::money($cents, $currency);
$type = $invoice['type'];
$isSample = $type === 'sample';
$isProforma = $type === 'proforma';
$isPaid = $invoice['status'] === 'paid';
$isCancelled = $invoice['status'] === 'cancelled';
$heading = ['sample' => 'Sample Invoice', 'proforma' => 'Proforma Invoice', 'commercial' => 'Invoice'][$type] ?? 'Invoice';
$buyer = $invoice['buyer'];
$consignee = $invoice['consignee'];
$lines = $invoice['lines'];
$hasHs = array_filter(array_column($lines, 'hs_code')) !== [];
$totalQty = array_sum(array_column($lines, 'qty'));
$units = array_values(array_unique(array_column($lines, 'unit')));

$factory = is_array($addresses) && isset($addresses[0]) ? $addresses[0] : null;
$sellerName = trim((string) ($company['legal_name'] ?? '')) !== '' ? $company['legal_name'] : ($company['name'] ?? 'PK Apparel');
$brandName = $company['name'] ?? 'PK Apparel';
$sellerAddress = $factory ? $factory['line'] . ', ' . $factory['city'] . ', ' . $factory['country'] : trim(($company['city'] ?? '') . ', ' . ($company['country'] ?? ''), ', ');

$dueLabel = $isProforma ? 'Valid until' : 'Due date';
$dueValue = $invoice['due_date'] !== null ? Format::date($invoice['due_date']) : ($isProforma ? '—' : 'On receipt');
$shippingLabel = $isSample ? 'Courier' : 'Freight';
$showPayment = !$isPaid && !$isCancelled && $amounts['due_now_cents'] > 0;
$dueNowLabel = $isProforma && $amounts['deposit_percent'] < 100 ? sprintf('Deposit due now (%d%%)', $amounts['deposit_percent']) : 'Amount due';

$party = static function (array $p) use ($e): string {
    $out = [];
    if (!empty($p['company'])) {
        $out[] = '<div class="party-name">' . $e($p['company']) . '</div>';
    }
    if (!empty($p['contact_name'])) {
        $out[] = empty($p['company']) ? '<div class="party-name">' . $e($p['contact_name']) . '</div>' : '<div>Attn: ' . $e($p['contact_name']) . '</div>';
    }
    if (!empty($p['address'])) {
        $out[] = '<div>' . nl2br($e($p['address'])) . '</div>';
    }
    if (!empty($p['country_code'])) {
        $out[] = '<div>' . $e(Format::country($p['country_code'])) . '</div>';
    }
    $contactLine = array_filter([$p['phone'] ?? null, $p['email'] ?? null]);
    if ($contactLine !== []) {
        $out[] = '<div class="muted" style="margin-top: 3px;">' . $e(implode(' · ', $contactLine)) . '</div>';
    }
    if (!empty($p['tax_id'])) {
        $out[] = '<div class="muted">Tax / import ID: ' . $e($p['tax_id']) . '</div>';
    }

    return implode('', $out);
};
$termLines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($invoice['terms'] ?? '')) ?: []), static fn (string $l): bool => $l !== ''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $e($invoice['type_label'] . ' ' . $invoice['invoice_no']) ?></title>
<style>
  @page { margin: 40px 42px 64px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6px; color: #0b132b; line-height: 1.45; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; padding: 0; }
  .muted { color: #5c6170; }
  .mono { font-family: 'DejaVu Sans Mono', monospace; }
  .num { text-align: right; white-space: nowrap; }
  .label { font-size: 6.8px; letter-spacing: 1.1px; text-transform: uppercase; color: #5c6170; font-weight: bold; }

  /* Page furniture repeated on every page */
  .band { position: fixed; top: -40px; left: -42px; right: -42px; height: 7px; background: #0b132b; }
  .band-accent { position: fixed; top: -33px; left: -42px; right: -42px; height: 2.5px; background: #e57a2e; }
  .footer { position: fixed; bottom: -44px; left: 0; right: 0; height: 26px; border-top: 1px solid #e3ddd0; padding-top: 6px; font-size: 6.8px; color: #8a8f9c; }
  .page-no:after { content: "Page " counter(page) " of <?= (int) ($pages ?? 1) ?>"; }

  /* Header */
  .co-name { font-size: 15px; font-weight: bold; color: #0b132b; letter-spacing: -0.2px; }
  .co-tag { font-size: 6.8px; letter-spacing: 1.6px; text-transform: uppercase; color: #e57a2e; font-weight: bold; margin-top: 1px; }
  .co-lines { margin-top: 5px; color: #3d4252; font-size: 7.6px; line-height: 1.55; }
  .doc-title { font-size: 21px; font-weight: bold; color: #0048ac; letter-spacing: 0.6px; text-transform: uppercase; }
  .doc-no { margin-top: 3px; font-size: 10px; }
  .stamp { display: inline-block; margin-top: 7px; padding: 3px 9px; border-radius: 10px; font-size: 7.4px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
  .stamp-paid { color: #047857; background: #ecfdf5; border: 1px solid #6ee7b7; }
  .stamp-cancelled { color: #b91c1c; background: #fef2f2; border: 1px solid #fca5a5; }

  /* Key facts strip */
  .facts td.fact { background: #f5f2eb; padding: 8px 11px; border-right: 2px solid #ffffff; }
  .facts .value { font-size: 9.4px; font-weight: bold; margin-top: 2px; white-space: nowrap; }
  .facts td.due { background: #0b132b; color: #ffffff; padding: 8px 12px; text-align: right; }
  .facts td.due .label { color: #f09a55; }
  .facts td.due .value { font-size: 15px; color: #ffffff; }

  /* Parties */
  .party-name { font-size: 9.6px; font-weight: bold; margin-bottom: 1px; }
  .party-box { border-top: 2px solid #0b132b; padding-top: 6px; }

  /* Lines */
  .lines th { background: #0b132b; color: #ffffff; font-size: 6.8px; letter-spacing: 1px; text-transform: uppercase; font-weight: bold; padding: 6px 7px; text-align: left; }
  .lines td { padding: 7px; border-bottom: 1px solid #ebe6dc; }
  .lines tr.alt td { background: #fbfaf7; }
  .lines .desc { font-weight: bold; font-size: 9px; }
  .lines .details { color: #5c6170; font-size: 7.8px; margin-top: 1px; }
  .lines tfoot td { border-bottom: none; border-top: 1.5px solid #0b132b; font-weight: bold; padding-top: 6px; }

  /* Totals */
  .totals td { padding: 3.5px 0; }
  .totals .total td { border-top: 1.5px solid #0b132b; padding-top: 6px; font-size: 10.5px; font-weight: bold; }
  .totals .due td { background: #fff4ea; border-top: 2px solid #e57a2e; padding: 7px 8px; font-size: 11px; font-weight: bold; color: #9a4a12; }
  .words { background: #fbfaf7; border-left: 3px solid #0048ac; padding: 6px 9px; }

  /* Payment */
  .pay { border: 1px solid #e3ddd0; border-top: 3px solid #e57a2e; }
  .pay-head { background: #fbfaf7; padding: 7px 12px; border-bottom: 1px solid #ebe6dc; }
  .pay-body { padding: 8px 12px 10px; }
  .bank td { padding: 2.5px 0; }
  .bank .k { color: #5c6170; width: 30%; white-space: nowrap; padding-right: 8px; }
  .bank .v { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.4px; }
  .ref { background: #0b132b; color: #ffffff; padding: 2px 6px; border-radius: 3px; font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; }
  .received { border: 1px solid #6ee7b7; background: #ecfdf5; color: #065f46; padding: 9px 12px; }

  h3 { font-size: 7px; letter-spacing: 1.2px; text-transform: uppercase; color: #5c6170; margin: 0 0 5px; }
  .terms li { margin-bottom: 2.5px; }
  .keep { page-break-inside: avoid; }
</style>
</head>
<body>
<div class="band"></div>
<div class="band-accent"></div>
<div class="footer">
  <table>
    <tr>
      <td>
        <strong style="color: #5c6170;"><?= $e($sellerName) ?></strong> · <?= $e($sellerAddress) ?><?php if (!empty($company['ntn'])): ?> · NTN <?= $e($company['ntn']) ?><?php endif; ?><br>
        <?= $e(implode(' · ', array_filter([$contact['phone'] ?? null, $contact['email'] ?? null, $website ?? null]))) ?>
      </td>
      <td class="num" style="width: 22%; vertical-align: bottom;">
        <span class="mono"><?= $e($invoice['invoice_no']) ?></span> · <span class="page-no"></span>
      </td>
    </tr>
  </table>
</div>

<!-- Seller and document title -->
<table>
  <tr>
    <td style="width: 60%;">
      <table>
        <tr>
          <td style="width: 60px;"><img src="<?= $logo ?>" style="height: 60px;" alt="<?= $e($brandName) ?>"></td>
          <td style="padding-left: 12px;">
            <div class="co-name"><?= $e($sellerName) ?></div>
            <?php if (!empty($company['tagline'])): ?><div class="co-tag"><?= $e($company['tagline']) ?></div><?php endif; ?>
            <div class="co-lines">
              <?= $e($sellerAddress) ?><br>
              <?= $e(implode('  ·  ', array_filter([$contact['phone'] ?? null, $contact['email'] ?? null]))) ?><br>
              <?= $e($website ?? '') ?><?php if (!empty($company['ntn'])): ?>  ·  NTN <?= $e($company['ntn']) ?><?php endif; ?>
            </div>
          </td>
        </tr>
      </table>
    </td>
    <td style="text-align: right;">
      <div class="doc-title"><?= $e($heading) ?></div>
      <div class="doc-no"><span class="muted">No.</span> <strong class="mono"><?= $e($invoice['invoice_no']) ?></strong></div>
      <?php if ($isPaid): ?>
        <div class="stamp stamp-paid">Paid<?= $invoice['paid_on'] ? ' · ' . $e(Format::date($invoice['paid_on'])) : '' ?></div>
      <?php elseif ($isCancelled): ?>
        <div class="stamp stamp-cancelled">Cancelled</div>
      <?php endif; ?>
    </td>
  </tr>
</table>

<!-- Key facts -->
<table class="facts" style="margin-top: 18px;">
  <tr>
    <td class="fact" style="width: 19%;"><div class="label">Invoice date</div><div class="value"><?= $e(Format::date($invoice['issue_date'])) ?></div></td>
    <td class="fact" style="width: 19%;"><div class="label"><?= $e($dueLabel) ?></div><div class="value"><?= $e($dueValue) ?></div></td>
    <td class="fact" style="width: 22%;">
      <?php if ($invoice['buyer_reference']): ?>
        <div class="label">Your reference</div><div class="value"><?= $e($invoice['buyer_reference']) ?></div>
      <?php else: ?>
        <div class="label">Currency</div><div class="value"><?= $e($currency) ?></div>
      <?php endif; ?>
    </td>
    <td class="due">
      <?php if ($isCancelled): ?>
        <div class="label">Status</div><div class="value">Cancelled</div>
      <?php elseif ($isPaid): ?>
        <div class="label">Total paid</div><div class="value"><?= $e($money($amounts['total_cents'])) ?></div>
      <?php else: ?>
        <div class="label"><?= $e($dueNowLabel) ?></div><div class="value"><?= $e($money($amounts['due_now_cents'])) ?></div>
      <?php endif; ?>
    </td>
  </tr>
</table>

<!-- Buyer, consignee, shipment -->
<table style="margin-top: 18px;">
  <tr>
    <td style="width: 35%; padding-right: 14px;">
      <div class="party-box">
        <h3>Bill to</h3>
        <?= $party($buyer) ?>
      </div>
    </td>
    <td style="width: 35%; padding-right: 14px;">
      <div class="party-box">
        <h3>Ship to</h3>
        <?php if ($consignee !== null): ?>
          <?= $party($consignee) ?>
        <?php else: ?>
          <span class="muted">Same as the buyer</span>
        <?php endif; ?>
      </div>
    </td>
    <td>
      <div class="party-box">
        <h3>Shipment</h3>
        <table>
          <tr><td class="muted" style="width: 46%; padding: 0 0 2px;">Ship by</td><td style="padding: 0 0 2px;"><?= $e($invoice['shipping_method'] ?: ($isSample ? 'Express courier' : 'To be agreed')) ?></td></tr>
          <?php if ($invoice['incoterm']): ?><tr><td class="muted" style="padding: 0 0 2px;">Incoterm</td><td style="padding: 0 0 2px;"><?= $e($invoice['incoterm']) ?></td></tr><?php endif; ?>
          <?php if ($invoice['delivery_time']): ?><tr><td class="muted" style="padding: 0 0 2px;"><?= $isSample ? 'Ready' : 'Delivery' ?></td><td style="padding: 0 0 2px;"><?= $e($invoice['delivery_time']) ?></td></tr><?php endif; ?>
          <tr><td class="muted" style="padding: 0 0 2px;">Origin</td><td style="padding: 0 0 2px;"><?= $e($company['country'] ?? 'Pakistan') ?></td></tr>
          <?php if ($invoice['buyer_reference']): ?><tr><td class="muted" style="padding: 0 0 2px;">Currency</td><td style="padding: 0 0 2px;"><?= $e($currency) ?></td></tr><?php endif; ?>
        </table>
      </div>
    </td>
  </tr>
</table>

<!-- Lines -->
<table class="lines" style="margin-top: 20px;">
  <thead>
    <tr>
      <th style="width: 4%;">#</th>
      <th>Description</th>
      <?php if ($hasHs): ?><th style="width: 11%;">HS code</th><?php endif; ?>
      <th class="num" style="width: 11%; text-align: right;">Qty</th>
      <th class="num" style="width: 13%; text-align: right;">Unit price</th>
      <th class="num" style="width: 14%; text-align: right;">Amount</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $index => $line): ?>
      <tr class="<?= $index % 2 === 1 ? 'alt' : '' ?>">
        <td class="muted"><?= $index + 1 ?></td>
        <td>
          <div class="desc"><?= $e($line['description']) ?></div>
          <?php if ($line['details']): ?><div class="details"><?= nl2br($e($line['details'])) ?></div><?php endif; ?>
        </td>
        <?php if ($hasHs): ?><td class="mono"><?= $e($line['hs_code'] ?? '') ?></td><?php endif; ?>
        <td class="num"><?= number_format($line['qty']) ?> <span class="muted"><?= $e($line['unit']) ?></span></td>
        <td class="num"><?= $e($money($line['unit_price_cents'])) ?></td>
        <td class="num"><strong><?= $e($money($line['amount_cents'])) ?></strong></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if (count($lines) > 1): ?>
    <tfoot>
      <tr>
        <td></td>
        <td>Total quantity</td>
        <?php if ($hasHs): ?><td></td><?php endif; ?>
        <td class="num"><?= number_format($totalQty) ?><?= count($units) === 1 ? ' <span class="muted">' . $e($units[0]) . '</span>' : '' ?></td>
        <td></td>
        <td class="num"><?= $e($money($amounts['subtotal_cents'])) ?></td>
      </tr>
    </tfoot>
  <?php endif; ?>
</table>

<!-- Notes, amount in words and totals -->
<table class="keep" style="margin-top: 14px;">
  <tr>
    <td style="width: 54%; padding-right: 22px;">
      <div class="words">
        <div class="label" style="margin-bottom: 2px;">Amount in words</div>
        <?= $e(Format::moneyInWords($isPaid || $isCancelled ? $amounts['total_cents'] : $amounts['due_now_cents'], $currency)) ?>
      </div>
      <?php if ($invoice['notes']): ?>
        <h3 style="margin-top: 12px;">Notes</h3>
        <div><?= nl2br($e($invoice['notes'])) ?></div>
      <?php endif; ?>
    </td>
    <td>
      <table class="totals">
        <tr><td>Subtotal</td><td class="num"><?= $e($money($amounts['subtotal_cents'])) ?></td></tr>
        <?php if ($amounts['shipping_cents'] > 0): ?>
          <tr><td><?= $e($shippingLabel) ?><?= $invoice['shipping_method'] ? ' <span class="muted">(' . $e($invoice['shipping_method']) . ')</span>' : '' ?></td><td class="num"><?= $e($money($amounts['shipping_cents'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($amounts['discount_cents'] > 0): ?>
          <tr><td>Discount</td><td class="num">−<?= $e($money($amounts['discount_cents'])) ?></td></tr>
        <?php endif; ?>
        <tr class="total"><td>Total</td><td class="num"><?= $e($money($amounts['total_cents'])) ?></td></tr>
        <?php if ($amounts['received_cents'] > 0): ?>
          <tr><td class="muted">Less: already received</td><td class="num muted">−<?= $e($money($amounts['received_cents'])) ?></td></tr>
        <?php endif; ?>
        <?php if (!$isPaid && !$isCancelled): ?>
          <tr class="due"><td><?= $e($dueNowLabel) ?></td><td class="num"><?= $e($money($amounts['due_now_cents'])) ?></td></tr>
          <?php if ($amounts['balance_after_cents'] > 0): ?>
            <tr><td class="muted" style="padding-top: 5px;">Balance before shipment</td><td class="num muted" style="padding-top: 5px;"><?= $e($money($amounts['balance_after_cents'])) ?></td></tr>
          <?php endif; ?>
        <?php endif; ?>
      </table>
    </td>
  </tr>
</table>

<!-- Payment -->
<?php if ($showPayment): ?>
<table class="pay keep" style="margin-top: 18px;">
  <tr>
    <td class="pay-head">
      <table>
        <tr>
          <td><strong style="font-size: 9.6px;">Payment by bank transfer</strong></td>
          <td class="num">Payment reference: <span class="ref"><?= $e($invoice['invoice_no']) ?></span></td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td class="pay-body">
      <?php if ($bank !== null): ?>
        <?php
        $bankRows = array_filter([
            'Beneficiary' => $bank['beneficiary'] ?? null,
            'Bank' => $bank['bank_name'] ?? null,
            'Branch' => $bank['branch'] ?? null,
            'Account number' => $bank['account_number'] ?? null,
            'IBAN' => $bank['iban'] ?? null,
            'SWIFT / BIC' => $bank['swift'] ?? null,
            'Bank address' => $bank['bank_address'] ?? null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
        $half = (int) ceil(count($bankRows) / 2);
        $columns = [array_slice($bankRows, 0, $half, true), array_slice($bankRows, $half, null, true)];
        ?>
        <table>
          <tr>
            <?php foreach ($columns as $i => $column): ?>
              <td style="width: 50%; <?= $i === 0 ? 'padding-right: 14px;' : '' ?>">
                <table class="bank">
                  <?php foreach ($column as $label => $value): ?>
                    <tr><td class="k"><?= $e($label) ?></td><td class="v"><?= $e($value) ?></td></tr>
                  <?php endforeach; ?>
                </table>
              </td>
            <?php endforeach; ?>
          </tr>
        </table>
        <div class="muted" style="margin-top: 6px;">
          Please pay <strong style="color: #0b132b;"><?= $e($money($amounts['due_now_cents'])) ?></strong><?= $invoice['due_date'] && !$isProforma ? ' by ' . $e(Format::date($invoice['due_date'])) : '' ?>
          and write <strong style="color: #0b132b;"><?= $e($invoice['invoice_no']) ?></strong> as the payment reference. Then send us the transfer slip or transaction ID.
          <?php if (!empty($bank['notes'])): ?><br><?= $e($bank['notes']) ?><?php endif; ?>
        </div>
      <?php else: ?>
        <div>Our team will send you the bank details for the transfer. Please write <strong><?= $e($invoice['invoice_no']) ?></strong> as the payment reference.</div>
      <?php endif; ?>
    </td>
  </tr>
</table>
<?php elseif ($isPaid): ?>
<table class="keep" style="margin-top: 18px;">
  <tr>
    <td class="received">
      <strong>Payment received<?= $invoice['paid_on'] ? ' on ' . $e(Format::date($invoice['paid_on'])) : '' ?>.</strong>
      <?php if ($invoice['payment_reference']): ?> Reference: <span class="mono"><?= $e($invoice['payment_reference']) ?></span>.<?php endif; ?>
      Thank you.
    </td>
  </tr>
</table>
<?php endif; ?>

<!-- Terms and sign-off -->
<table class="keep" style="margin-top: 18px;">
  <tr>
    <td style="width: 64%; padding-right: 24px;">
      <?php if ($termLines !== []): ?>
        <h3>Terms</h3>
        <ul class="terms" style="margin: 0; padding-left: 12px;">
          <?php foreach ($termLines as $term): ?><li><?= $e($term) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </td>
    <td style="vertical-align: bottom; text-align: center;">
      <div style="font-size: 9.6px; font-weight: bold; color: #0048ac;">Thank you for your business</div>
      <div style="border-top: 1px solid #0b132b; margin-top: 26px; padding-top: 4px;">For <?= $e($sellerName) ?></div>
      <div class="muted" style="font-size: 6.8px; margin-top: 2px;">Computer-generated document, valid without a signature</div>
    </td>
  </tr>
</table>
</body>
</html>
