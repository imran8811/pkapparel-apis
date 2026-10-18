<?php
/**
 * Staff notification for a new inquiry.
 * Variables: $inquiry (array), $files (attached uploads), $typeLabel (string), $reference (string), $adminUrl (string), $e (escaper).
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
<?php if ($files !== []): ?>
<h2 style="margin:24px 0 8px;font-size:16px;">Attached files</h2>
<ul style="margin:0;padding-left:18px;font-size:14px;">
  <?php foreach ($files as $file): ?>
    <li style="margin:0 0 4px;"><?= $e($file['name']) ?> <span style="color:#5c6170;">(<?= $e(number_format(max(1, $file['size_bytes'] / 1024))) ?> KB)</span></li>
  <?php endforeach; ?>
</ul>
<p style="margin:8px 0 0;color:#5c6170;font-size:13px;">Download them from the inquiry in the admin.</p>
<?php endif; ?>
<p style="margin:24px 0 0;">
  <a href="<?= $e($adminUrl) ?>" style="display:inline-block;background:#0b1430;color:#ffffff;text-decoration:none;font-weight:bold;padding:11px 20px;border-radius:999px;">Open in the admin</a>
</p>
