<?php
/**
 * A quotation's costing sheet, for staff (never sent to the buyer): how each style's price was made, the styles side
 * by side (six per table) with the fabric, every cost, the profit and the prices. Costs no style has are left out.
 * A4 landscape, rendered by dompdf: tables for layout, DejaVu Sans.
 * Variables: $quotation (Quotations::present), $company, $contact, $website, $pages, $logo (data URI), $e (escaper).
 */

use App\Core\Format;
use App\Services\Quotations;

$currency = $quotation['currency'];
$isRupees = $currency === 'PKR';
$rupees = static fn (int $cents): string => Format::money($cents, 'PKR');
$quoted = static fn (int $cents): string => Format::money($cents, $currency);
$styles = $quotation['styles'];
$hasQty = $quotation['total_qty'] > 0;
$sellerName = trim((string) ($company['legal_name'] ?? '')) !== '' ? $company['legal_name'] : ($company['name'] ?? 'PK Apparel');
$buyer = $quotation['buyer'];
$buyerName = $buyer['company'] ?? $buyer['contact_name'] ?? '';
$any = static fn (callable $test): bool => array_filter($styles, $test) !== [];
$hasFabric = $any(static fn (array $s): bool => $s['fabric_rate_cents'] > 0);
$hasShipping = $any(static fn (array $s): bool => $s['shipping_cents'] > 0);
$costs = array_filter(Quotations::COSTS, static fn (string $key): bool => $any(static fn (array $s): bool => $s["{$key}_cents"] > 0), ARRAY_FILTER_USE_KEY);
$chunks = array_chunk($styles, 6, true);
$rate = $quotation['exchange_rate'];
$rateText = $isRupees || $rate === null ? null : sprintf('1 %s = Rs %s', $currency, $rate);
$percentOf = static fn (int $part, int $whole): string => $whole > 0 ? rtrim(rtrim(number_format($part * 100 / $whole, 1), '0'), '.') . '%' : '—';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $e('Costing sheet ' . $quotation['quotation_no']) ?></title>
<style>
  @page { margin: 34px 38px 56px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.2px; color: #0b132b; line-height: 1.4; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; padding: 0; }
  .muted { color: #5c6170; }
  .mono { font-family: 'DejaVu Sans Mono', monospace; }
  .num { text-align: right; white-space: nowrap; }
  .label { font-size: 6.6px; letter-spacing: 1.1px; text-transform: uppercase; color: #5c6170; font-weight: bold; }

  .band { position: fixed; top: -34px; left: -38px; right: -38px; height: 6px; background: #0b132b; }
  .band-accent { position: fixed; top: -28px; left: -38px; right: -38px; height: 2.5px; background: #e57a2e; }
  .footer { position: fixed; bottom: -38px; left: 0; right: 0; height: 18px; border-top: 1px solid #e3ddd0; padding-top: 5px; font-size: 6.8px; color: #8a8f9c; }
  .page-no:after { content: "Page " counter(page) " of <?= (int) ($pages ?? 1) ?>"; }

  .title { font-size: 17px; font-weight: bold; color: #0048ac; letter-spacing: 0.5px; text-transform: uppercase; }
  .internal { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 9px; font-size: 6.8px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #9a4a12; background: #fff4ea; border: 1px solid #f7b985; vertical-align: middle; }
  .facts td { background: #f5f2eb; padding: 5px 10px; border-right: 2px solid #ffffff; }
  .facts .value { font-size: 9px; font-weight: bold; margin-top: 1px; }

  .sheet th { background: #0b132b; color: #ffffff; padding: 5px 7px; text-align: right; font-size: 8px; }
  .sheet th.k { text-align: left; font-size: 6.6px; letter-spacing: 1px; text-transform: uppercase; }
  .sheet td { padding: 2.5px 7px; border-bottom: 1px solid #ebe6dc; text-align: right; white-space: nowrap; }
  .sheet td.k { text-align: left; color: #3d4252; white-space: normal; }
  .sheet td .sub { display: block; font-size: 6.4px; line-height: 1.2; color: #8a8f9c; white-space: normal; }
  .sheet tr.desc td { white-space: normal; font-size: 7.4px; color: #3d4252; background: #fbfaf7; }
  .sheet tr.section td { background: #f5f2eb; padding-top: 2px; padding-bottom: 2px; font-size: 6.2px; letter-spacing: 1px; text-transform: uppercase; font-weight: bold; color: #5c6170; text-align: left; }
  .sheet tr.total td { border-top: 1.5px solid #0b132b; font-weight: bold; }
  .sheet tr.price td { font-weight: bold; font-size: 9px; }
  .sheet tr.quoted td { background: #0b132b; color: #ffffff; font-weight: bold; font-size: 9.6px; border-bottom: none; padding-top: 4px; padding-bottom: 4px; }
  .sheet tr.quoted td.k { color: #f09a55; }
  .sheet tr.amount td { font-weight: bold; }
  .sheet { page-break-inside: avoid; }
  h3 { font-size: 6.8px; letter-spacing: 1.2px; text-transform: uppercase; color: #5c6170; margin: 0 0 4px; }
  .box { border: 1px solid #e3ddd0; padding: 7px 10px; }
</style>
</head>
<body>
<div class="band"></div>
<div class="band-accent"></div>
<div class="footer">
  <table>
    <tr>
      <td><strong style="color: #5c6170;"><?= $e($sellerName) ?></strong> · Costing sheet for staff: not for the buyer</td>
      <td class="num"><span class="mono"><?= $e($quotation['quotation_no']) ?></span> · <span class="page-no"></span></td>
    </tr>
  </table>
</div>

<table>
  <tr>
    <td style="width: 46px;"><img src="<?= $logo ?>" style="height: 40px;" alt=""></td>
    <td style="padding-left: 10px; vertical-align: middle;">
      <span class="title">Costing sheet</span><span class="internal">Internal</span>
      <div style="margin-top: 2px; font-size: 9.4px;"><span class="muted">Quotation</span> <strong class="mono"><?= $e($quotation['quotation_no']) ?></strong> · <?= $e($quotation['stage_label']) ?></div>
    </td>
    <td class="num" style="vertical-align: middle; color: #5c6170;">
      All costs per piece in Pakistani rupees<?= $rateText !== null ? '<br>Quoted in ' . $e($currency) . ' at ' . $e($rateText) : '' ?>
    </td>
  </tr>
</table>

<table class="facts" style="margin-top: 10px;">
  <tr>
    <td style="width: 34%;"><div class="label">Buyer</div><div class="value"><?= $e($buyerName) ?><?= !empty($buyer['country_code']) ? ' <span class="muted" style="font-weight: normal;">· ' . $e(Format::country($buyer['country_code'])) . '</span>' : '' ?></div></td>
    <td style="width: 14%;"><div class="label">Date</div><div class="value"><?= $e(Format::date($quotation['issue_date'])) ?></div></td>
    <td style="width: 14%;"><div class="label">Valid until</div><div class="value"><?= $e($quotation['valid_until'] !== null ? Format::date($quotation['valid_until']) : '—') ?></div></td>
    <td style="width: 16%;"><div class="label">Their reference</div><div class="value"><?= $e($quotation['buyer_reference'] ?: '—') ?></div></td>
    <td><div class="label"><?= $hasQty ? 'Total for ' . number_format($quotation['total_qty']) . ' pcs' : 'Styles' ?></div><div class="value"><?= $hasQty ? $e($quoted($quotation['total_cents'])) : count($styles) ?></div></td>
  </tr>
</table>

<?php foreach ($chunks as $chunk): ?>
  <?php $width = sprintf('%.2F%%', 78 / 6); ?>
  <table class="sheet" style="margin-top: 10px;">
    <thead>
      <tr>
        <th class="k" style="width: 22%;">Per piece</th>
        <?php foreach ($chunk as $index => $style): ?>
          <th style="width: <?= $width ?>;"><span style="font-weight: normal; color: #9bb8ea;"><?= $index + 1 ?></span> <?= $e($style['article_no'] ?? 'Style') ?></th>
        <?php endforeach; ?>
        <?php for ($i = count($chunk); $i < 6; $i++): ?><th style="width: <?= $width ?>;"></th><?php endfor; ?>
      </tr>
    </thead>
    <tbody>
      <tr class="desc">
        <td class="k">Style</td>
        <?php foreach ($chunk as $style): ?><td><?= $e($style['description'] ?? '') ?><?= $style['fabric'] ? '<span class="sub">' . $e($style['fabric']) . '</span>' : '' ?></td><?php endforeach; ?>
        <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
      </tr>
      <?php if ($hasQty): ?>
        <tr>
          <td class="k">Quantity</td>
          <?php foreach ($chunk as $style): ?><td><?= $style['qty'] !== null ? number_format($style['qty']) . ' pcs' : '—' ?></td><?php endforeach; ?>
          <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
        </tr>
      <?php endif; ?>

      <?php if ($hasFabric): ?>
        <tr class="section"><td colspan="7">Fabric</td></tr>
        <tr>
          <td class="k">Rate</td>
          <?php foreach ($chunk as $style): ?><td><?= $style['fabric_rate_cents'] > 0 ? $e($rupees($style['fabric_rate_cents'])) . ' / ' . $e($style['fabric_unit']) : '—' ?></td><?php endforeach; ?>
          <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
        </tr>
        <tr>
          <td class="k">Consumption</td>
          <?php foreach ($chunk as $style): ?><td><?= $style['fabric_rate_cents'] > 0 ? $e($style['fabric_consumption'] . ' ' . $style['fabric_unit']) : '—' ?></td><?php endforeach; ?>
          <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
        </tr>
        <tr>
          <td class="k"><strong>Fabric cost</strong> <span class="muted">(rate × consumption)</span></td>
          <?php foreach ($chunk as $style): ?><td><strong><?= $style['fabric_cost_cents'] > 0 ? $e($rupees($style['fabric_cost_cents'])) : '—' ?></strong></td><?php endforeach; ?>
          <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
        </tr>
      <?php endif; ?>

      <?php if ($costs !== []): ?>
        <tr class="section"><td colspan="7">Making and other costs</td></tr>
        <?php foreach ($costs as $key => $label): ?>
          <tr>
            <td class="k"><?= $e($label) ?></td>
            <?php foreach ($chunk as $style): ?>
              <td>
                <?= $style["{$key}_cents"] > 0 ? $e($rupees($style["{$key}_cents"])) : '—' ?>
                <?php if ($key === 'extra' && $style['extra_label']): ?><span class="sub"><?= $e($style['extra_label']) ?></span><?php endif; ?>
              </td>
            <?php endforeach; ?>
            <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>

      <tr class="total">
        <td class="k">Total cost</td>
        <?php foreach ($chunk as $style): ?><td><?= $e($rupees($style['cost_cents'])) ?></td><?php endforeach; ?>
        <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
      </tr>
      <tr>
        <td class="k">Profit</td>
        <?php foreach ($chunk as $style): ?>
          <td>
            <?= $e($rupees($style['profit_cents'])) ?>
            <span class="sub"><?= $style['profit_type'] === 'percent' ? $e(($style['profit_percent'] ?? '0') . '% of the cost') : $e($percentOf($style['profit_cents'], $style['cost_cents']) . ' of the cost') ?></span>
          </td>
        <?php endforeach; ?>
        <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
      </tr>
      <?php if ($hasShipping): ?>
        <tr>
          <td class="k">Shipping <span class="muted">(after the profit)</span></td>
          <?php foreach ($chunk as $style): ?><td><?= $style['shipping_cents'] > 0 ? $e($rupees($style['shipping_cents'])) : '—' ?></td><?php endforeach; ?>
          <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
        </tr>
      <?php endif; ?>
      <tr class="price">
        <td class="k">Price <span class="muted" style="font-weight: normal;">(cost + profit<?= $hasShipping ? ' + shipping' : '' ?>)</span></td>
        <?php foreach ($chunk as $style): ?><td><?= $e($rupees($style['price_cents'])) ?></td><?php endforeach; ?>
        <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
      </tr>
      <tr class="quoted">
        <td class="k">Quoted per piece<?= $isRupees ? ' (to the rupee)' : ' in ' . $e($currency) ?></td>
        <?php foreach ($chunk as $style): ?><td><?= $e($quoted($style['unit_price_cents'])) ?></td><?php endforeach; ?>
        <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
      </tr>
      <?php if ($hasQty): ?>
        <tr class="amount">
          <td class="k">Amount <span class="muted" style="font-weight: normal;">(quoted × quantity)</span></td>
          <?php foreach ($chunk as $style): ?><td><?= $style['amount_cents'] !== null ? $e($quoted($style['amount_cents'])) : '—' ?></td><?php endforeach; ?>
          <?php for ($i = count($chunk); $i < 6; $i++): ?><td></td><?php endfor; ?>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
<?php endforeach; ?>

<table style="margin-top: 10px;">
  <tr>
    <td style="width: 50%; padding-right: 14px;">
      <?php if ($quotation['staff_notes']): ?>
        <div class="box">
          <h3>Staff notes</h3>
          <?= nl2br($e($quotation['staff_notes'])) ?>
        </div>
      <?php endif; ?>
    </td>
    <td class="muted" style="text-align: right;">
      <?= $quotation['created_by'] ? 'Made by ' . $e($quotation['created_by']) . ' · ' : '' ?>Printed <?= $e(Format::date(gmdate(DATE_ATOM))) ?>
      <br>Fabric cost = rate × consumption. Profit is on the total cost<?= $hasShipping ? '; shipping is added after it' : '' ?>. Prices in <?= $isRupees ? 'rupees are rounded to the rupee' : $e($currency) . ' are the rupee price ÷ the exchange rate, to the cent' ?>.
    </td>
  </tr>
</table>
</body>
</html>
