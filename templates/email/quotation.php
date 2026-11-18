<?php
/**
 * A quotation made in Admin → Quotations, emailed to the buyer with the PDF attached: the prices per piece, never the
 * costs. Variables: $quotation (Quotations::present), $message (staff's own words, or null), $e (escaper).
 */

use App\Core\Format;

$currency = $quotation['currency'];
$money = static fn (int $cents): string => Format::money($cents, $currency);
$buyer = $quotation['buyer'];
$greeting = $buyer['contact_name'] ?? null;
$styles = $quotation['styles'];
$shown = array_slice($styles, 0, 20);
$count = count($styles);
$facts = array_filter([
    'Quotation' => $quotation['quotation_no'],
    'Date' => Format::date($quotation['issue_date']),
    'Your reference' => $quotation['buyer_reference'],
    'Valid until' => $quotation['valid_until'] !== null ? Format::date($quotation['valid_until']) : null,
    'Prices' => 'Per piece, in ' . $currency . ($quotation['incoterm'] ? ' · ' . $quotation['incoterm'] : ''),
    'Delivery' => $quotation['delivery_time'],
], static fn (?string $value): bool => $value !== null && $value !== '');
?>
<h1 style="margin:0 0 12px;font-size:20px;">Your quotation <?= $e($quotation['quotation_no']) ?></h1>
<p style="margin:0 0 14px;"><?= $greeting ? 'Dear ' . $e($greeting) . ',' : 'Hello,' ?></p>

<?php if ($message): ?>
  <p style="margin:0 0 14px;"><?= nl2br($e($message)) ?></p>
<?php endif; ?>

<p style="margin:0 0 18px;">
  Thank you for your inquiry. Please find attached our quotation <?= $e($quotation['quotation_no']) ?> for <?= $count === 1 ? 'your style' : 'your ' . $count . ' styles' ?>, with the details of each.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
  <?php foreach ($facts as $label => $value): ?>
    <tr>
      <td style="padding:4px 14px 4px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;"><?= $e($label) ?></td>
      <td style="padding:4px 0;font-size:14px;"><strong><?= $e($value) ?></strong></td>
    </tr>
  <?php endforeach; ?>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 18px;border-collapse:collapse;">
  <tr>
    <td style="padding:6px 8px;background:#0b132b;color:#ffffff;font-size:11px;letter-spacing:1px;text-transform:uppercase;">Style</td>
    <td style="padding:6px 8px;background:#0b132b;color:#ffffff;font-size:11px;letter-spacing:1px;text-transform:uppercase;text-align:right;">Price per piece</td>
  </tr>
  <?php foreach ($shown as $style): ?>
    <tr>
      <td style="padding:7px 8px;border-bottom:1px solid #ebe6dc;font-size:14px;">
        <?php if ($style['article_no']): ?><strong style="font-family:monospace;color:#0048ac;"><?= $e($style['article_no']) ?></strong> <?php endif; ?>
        <?= $e($style['description'] ?? '') ?>
        <?php if ($style['qty'] !== null): ?><span style="color:#5c6170;font-size:12px;"> · <?= number_format($style['qty']) ?> pcs</span><?php endif; ?>
      </td>
      <td style="padding:7px 8px;border-bottom:1px solid #ebe6dc;font-size:14px;text-align:right;white-space:nowrap;"><strong><?= $e($money($style['unit_price_cents'])) ?></strong></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($count > count($shown)): ?>
    <tr><td colspan="2" style="padding:7px 8px;color:#5c6170;font-size:13px;">And <?= $count - count($shown) ?> more in the PDF.</td></tr>
  <?php endif; ?>
  <?php if ($quotation['total_qty'] > 0): ?>
    <tr>
      <td style="padding:8px;font-size:14px;"><strong>Total for <?= number_format($quotation['total_qty']) ?> pcs</strong></td>
      <td style="padding:8px;font-size:14px;text-align:right;white-space:nowrap;"><strong><?= $e($money($quotation['total_cents'])) ?></strong></td>
    </tr>
  <?php endif; ?>
</table>

<p style="margin:0 0 14px;">To go ahead, reply to this email to confirm the styles and quantities, and we will send the proforma invoice.</p>
<p style="margin:0;font-size:13px;color:#5c6170;">Questions? Reply to this email or message us on WhatsApp.</p>
