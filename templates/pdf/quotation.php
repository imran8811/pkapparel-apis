<?php
/**
 * A quotation made in Admin → Quotations, for the buyer: each style with its price per piece, never the costs or the
 * profit (those are on the costing sheet, for staff). Shipping per piece is in the prices; the price basis says so. A4, rendered by dompdf: tables for layout (no flexbox or grid),
 * DejaVu Sans for full Unicode (€, £, —).
 * Variables: $quotation (Quotations::present), $company, $contact, $addresses (settings), $website
 * ("www.pkapparel.com"), $pages (page count, for the footer), $logo (data URI), $e (escaper).
 */

use App\Core\Format;

$currency = $quotation['currency'];
$money = static fn (int $cents): string => Format::money($cents, $currency);
$styles = $quotation['styles'];
$buyer = $quotation['buyer'];
$hasQty = $quotation['total_qty'] > 0;
// The styles whose price includes shipping ("1", "3"): all of them, some or none.
$shipped = array_map(static fn (int $index): int => $index + 1, array_keys(array_filter($styles, static fn (array $s): bool => $s['shipping_cents'] > 0)));
$isAccepted = $quotation['status'] === 'accepted';
$currencyName = ['USD' => 'US dollars', 'EUR' => 'euros', 'GBP' => 'pounds sterling', 'PKR' => 'Pakistani rupees'][$currency] ?? $currency;

$factory = is_array($addresses) && isset($addresses[0]) ? $addresses[0] : null;
$sellerName = trim((string) ($company['legal_name'] ?? '')) !== '' ? $company['legal_name'] : ($company['name'] ?? 'PK Apparel');
$brandName = $company['name'] ?? 'PK Apparel';
$sellerAddress = $factory ? Format::companyAddress($factory) : trim(($company['city'] ?? '') . ', ' . ($company['country'] ?? ''), ', ');

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

    return implode('', $out);
};
$termLines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($quotation['terms'] ?? '')) ?: []), static fn (string $l): bool => $l !== ''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $e('Quotation ' . $quotation['quotation_no']) ?></title>
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
  .stamp { display: inline-block; margin-top: 7px; padding: 3px 9px; border-radius: 10px; font-size: 7.4px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #047857; background: #ecfdf5; border: 1px solid #6ee7b7; }

  /* Key facts strip */
  .facts td.fact { background: #f5f2eb; padding: 8px 11px; border-right: 2px solid #ffffff; }
  .facts .value { font-size: 9.4px; font-weight: bold; margin-top: 2px; white-space: nowrap; }
  .facts td.lead { background: #0b132b; color: #ffffff; padding: 8px 12px; text-align: right; }
  .facts td.lead .label { color: #f09a55; }
  .facts td.lead .value { font-size: 15px; color: #ffffff; }

  /* Parties */
  .party-name { font-size: 9.6px; font-weight: bold; margin-bottom: 1px; }
  .party-box { border-top: 2px solid #0b132b; padding-top: 6px; }
  .basis td { padding: 0 0 2px; }
  .basis td.k { width: 38%; color: #5c6170; }

  /* Styles */
  .styles th { background: #0b132b; color: #ffffff; font-size: 6.8px; letter-spacing: 1px; text-transform: uppercase; font-weight: bold; padding: 6px 7px; text-align: left; }
  .styles td { padding: 8px 7px; border-bottom: 1px solid #ebe6dc; }
  .styles tr.alt td { background: #fbfaf7; }
  .styles tr { page-break-inside: avoid; }
  .styles .article { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; font-size: 9px; color: #0048ac; }
  .styles .desc { font-weight: bold; font-size: 9px; }
  .styles .spec { color: #5c6170; font-size: 7.8px; margin-top: 2px; }
  .styles .spec strong { color: #3d4252; }
  .styles .price { font-size: 10px; font-weight: bold; }
  .styles tfoot td { border-bottom: none; border-top: 1.5px solid #0b132b; font-weight: bold; padding-top: 6px; }

  h3 { font-size: 7px; letter-spacing: 1.2px; text-transform: uppercase; color: #5c6170; margin: 0 0 5px; }
  .note { background: #fbfaf7; border-left: 3px solid #0048ac; padding: 6px 9px; }
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
        <span class="mono"><?= $e($quotation['quotation_no']) ?></span> · <span class="page-no"></span>
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
      <div class="doc-title">Quotation</div>
      <div class="doc-no"><span class="muted">No.</span> <strong class="mono"><?= $e($quotation['quotation_no']) ?></strong></div>
      <?php if ($isAccepted): ?>
        <div class="stamp">Accepted<?= $quotation['decided_at'] ? ' · ' . $e(Format::date($quotation['decided_at'])) : '' ?></div>
      <?php endif; ?>
    </td>
  </tr>
</table>

<!-- Key facts -->
<table class="facts" style="margin-top: 18px;">
  <tr>
    <td class="fact" style="width: 19%;"><div class="label">Date</div><div class="value"><?= $e(Format::date($quotation['issue_date'])) ?></div></td>
    <td class="fact" style="width: 19%;"><div class="label">Valid until</div><div class="value"><?= $e($quotation['valid_until'] !== null ? Format::date($quotation['valid_until']) : '—') ?></div></td>
    <td class="fact" style="width: 22%;">
      <?php if ($quotation['buyer_reference']): ?>
        <div class="label">Your reference</div><div class="value"><?= $e($quotation['buyer_reference']) ?></div>
      <?php else: ?>
        <div class="label">Styles</div><div class="value"><?= count($styles) ?></div>
      <?php endif; ?>
    </td>
    <td class="lead">
      <?php if ($hasQty): ?>
        <div class="label">Total for <?= number_format($quotation['total_qty']) ?> pcs</div><div class="value"><?= $e($money($quotation['total_cents'])) ?></div>
      <?php else: ?>
        <div class="label">Prices per piece in</div><div class="value"><?= $e($currency) ?></div>
      <?php endif; ?>
    </td>
  </tr>
</table>

<!-- Buyer and price basis -->
<table style="margin-top: 18px;">
  <tr>
    <td style="width: 50%; padding-right: 14px;">
      <div class="party-box">
        <h3>Prepared for</h3>
        <?= $party($buyer) ?>
      </div>
    </td>
    <td>
      <div class="party-box">
        <h3>Price basis</h3>
        <table class="basis">
          <tr><td class="k">Prices</td><td>Per piece, in <?= $e($currencyName) ?> (<?= $e($currency) ?>)</td></tr>
          <?php if ($quotation['incoterm']): ?><tr><td class="k">Incoterm</td><td><?= $e($quotation['incoterm']) ?></td></tr><?php endif; ?>
          <?php if ($quotation['delivery_time']): ?><tr><td class="k">Delivery</td><td><?= $e($quotation['delivery_time']) ?></td></tr><?php endif; ?>
          <?php if ($shipped !== []): ?><tr><td class="k">Shipping</td><td>Included in the prices<?= count($shipped) < count($styles) ? ' of style' . (count($shipped) > 1 ? 's ' : ' ') . $e(implode(', ', $shipped)) : '' ?></td></tr><?php endif; ?>
          <tr><td class="k">Origin</td><td><?= $e($company['country'] ?? 'Pakistan') ?></td></tr>
        </table>
      </div>
    </td>
  </tr>
</table>

<!-- Styles -->
<table class="styles" style="margin-top: 20px;">
  <thead>
    <tr>
      <th style="width: 4%;">#</th>
      <th>Style</th>
      <?php if ($hasQty): ?><th class="num" style="width: 11%; text-align: right;">Qty</th><?php endif; ?>
      <th class="num" style="width: 15%; text-align: right;">Price per piece</th>
      <?php if ($hasQty): ?><th class="num" style="width: 15%; text-align: right;">Amount</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($styles as $index => $style): ?>
      <?php
      $specs = array_filter([
          'Fabric' => $style['fabric'],
          'Colors' => $style['colors'],
          'Sizes' => $style['sizes'],
      ], static fn (?string $value): bool => $value !== null && $value !== '');
      ?>
      <tr class="<?= $index % 2 === 1 ? 'alt' : '' ?>">
        <td class="muted"><?= $index + 1 ?></td>
        <td>
          <?php if ($style['article_no']): ?><span class="article"><?= $e($style['article_no']) ?></span><?php if ($style['description']): ?>&nbsp;&nbsp;<?php endif; ?><?php endif; ?>
          <?php if ($style['description']): ?><span class="desc"><?= $e($style['description']) ?></span><?php endif; ?>
          <?php foreach ($specs as $label => $value): ?>
            <div class="spec"><strong><?= $e($label) ?>:</strong> <?= $e($value) ?></div>
          <?php endforeach; ?>
          <?php if ($style['details']): ?><div class="spec"><?= nl2br($e($style['details'])) ?></div><?php endif; ?>
        </td>
        <?php if ($hasQty): ?>
          <td class="num"><?= $style['qty'] !== null ? number_format($style['qty']) . ' <span class="muted">pcs</span>' : '<span class="muted">—</span>' ?></td>
        <?php endif; ?>
        <td class="num price"><?= $e($money($style['unit_price_cents'])) ?></td>
        <?php if ($hasQty): ?>
          <td class="num"><?= $style['amount_cents'] !== null ? '<strong>' . $e($money($style['amount_cents'])) . '</strong>' : '<span class="muted">—</span>' ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if ($hasQty && count($styles) > 1): ?>
    <tfoot>
      <tr>
        <td></td>
        <td>Total</td>
        <td class="num"><?= number_format($quotation['total_qty']) ?> <span class="muted">pcs</span></td>
        <td></td>
        <td class="num"><?= $e($money($quotation['total_cents'])) ?></td>
      </tr>
    </tfoot>
  <?php endif; ?>
</table>

<?php if ($quotation['notes']): ?>
<table class="keep" style="margin-top: 14px;">
  <tr>
    <td class="note">
      <div class="label" style="margin-bottom: 2px;">Notes</div>
      <?= nl2br($e($quotation['notes'])) ?>
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
      <div style="font-size: 9.6px; font-weight: bold; color: #0048ac;">We look forward to working with you</div>
      <div style="border-top: 1px solid #0b132b; margin-top: 26px; padding-top: 4px;">For <?= $e($sellerName) ?></div>
      <div class="muted" style="font-size: 6.8px; margin-top: 2px;">Computer-generated document, valid without a signature</div>
    </td>
  </tr>
</table>
</body>
</html>
