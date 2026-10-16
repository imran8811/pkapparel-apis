<?php
/**
 * Staff notification for a new inquiry.
 * Variables: $inquiry (array), $typeLabel (string), $reference (string), $e (escaper).
 */
$rows = [
    'Reference' => $reference,
    'Type' => $typeLabel,
    'Name' => $inquiry['name'],
    'Company' => $inquiry['company'] ?? '',
    'Email' => $inquiry['email'],
    'Phone / WhatsApp' => $inquiry['phone'] ?? '',
    'Country' => $inquiry['country_code'] ?? '',
    'Sent from page' => $inquiry['source_url'] ?? '',
];
foreach (($inquiry['details'] ?? []) as $label => $value) {
    $rows[ucfirst(str_replace('_', ' ', (string) $label))] = is_array($value) ? implode(', ', $value) : $value;
}
?>
<h1 style="margin:0 0 12px;font-size:20px;">New <?= $e(strtolower($typeLabel)) ?> from <?= $e($inquiry['name']) ?></h1>
<p style="margin:0 0 18px;color:#5c6170;">Reply directly to this email to answer <?= $e($inquiry['name']) ?>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
  <?php foreach ($rows as $label => $value): ?>
    <?php if ((string) $value === '') { continue; } ?>
    <tr>
      <td style="padding:8px 12px 8px 0;border-bottom:1px solid #eae4d8;color:#5c6170;white-space:nowrap;vertical-align:top;"><?= $e($label) ?></td>
      <td style="padding:8px 0;border-bottom:1px solid #eae4d8;"><?= $e($value) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<h2 style="margin:24px 0 8px;font-size:16px;">Message</h2>
<p style="margin:0;white-space:pre-line;"><?= $e($inquiry['message']) ?></p>
