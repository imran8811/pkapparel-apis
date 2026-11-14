<?php
/**
 * An invoice made in Admin → Invoices, emailed to the buyer with the PDF attached.
 * Variables: $invoice (Invoices::present), $message (staff's own words, or null), $bank (array|null), $e (escaper).
 */

use App\Core\Format;

$amounts = $invoice['amounts'];
$money = static fn (int $cents): string => Format::money($cents, $invoice['currency']);
$isProforma = $invoice['type'] === 'proforma';
$isPaid = $invoice['status'] === 'paid';
$buyer = $invoice['buyer'];
$greeting = $buyer['contact_name'] ?? null;
$typeLabel = mb_strtolower($invoice['type_label']);
$facts = array_filter([
    'Invoice' => $invoice['invoice_no'],
    'Date' => Format::date($invoice['issue_date']),
    'Your reference' => $invoice['buyer_reference'],
    'Total' => $money($amounts['total_cents']),
    ($isProforma && $amounts['deposit_percent'] < 100 ? sprintf('Deposit due now (%d%%)', $amounts['deposit_percent']) : 'Amount due') => $isPaid ? null : $money($amounts['due_now_cents']),
    ($isProforma ? 'Valid until' : 'Due date') => $invoice['due_date'] ? Format::date($invoice['due_date']) : null,
    'Payment reference' => $isPaid ? null : $invoice['invoice_no'],
], static fn (?string $value): bool => $value !== null && $value !== '');
?>
<h1 style="margin:0 0 12px;font-size:20px;">Your <?= $e($typeLabel) ?> <?= $e($invoice['invoice_no']) ?></h1>
<p style="margin:0 0 14px;"><?= $greeting ? 'Dear ' . $e($greeting) . ',' : 'Hello,' ?></p>

<?php if ($message): ?>
  <p style="margin:0 0 14px;"><?= nl2br($e($message)) ?></p>
<?php endif; ?>

<p style="margin:0 0 18px;">
  <?php if ($isPaid): ?>
    Please find attached <?= $e($typeLabel) ?> <?= $e($invoice['invoice_no']) ?>, marked as paid. Thank you for your payment.
  <?php else: ?>
    Please find attached <?= $e($typeLabel) ?> <?= $e($invoice['invoice_no']) ?>. To go ahead, please transfer <strong><?= $e($money($amounts['due_now_cents'])) ?></strong> to the account below and write the invoice number as the payment reference.
  <?php endif; ?>
</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
  <?php foreach ($facts as $label => $value): ?>
    <tr>
      <td style="padding:4px 14px 4px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;"><?= $e($label) ?></td>
      <td style="padding:4px 0;font-size:14px;"><strong><?= $e($value) ?></strong></td>
    </tr>
  <?php endforeach; ?>
</table>

<?php if (!$isPaid && $amounts['due_now_cents'] > 0): ?>
  <?php require __DIR__ . '/partials/bank-details.php'; ?>
  <p style="margin:0 0 14px;">After the transfer, please reply to this email with the transfer slip or the transaction ID, so we can match your payment quickly.</p>
<?php endif; ?>

<p style="margin:0;font-size:13px;color:#5c6170;">Questions? Reply to this email or message us on WhatsApp.</p>
