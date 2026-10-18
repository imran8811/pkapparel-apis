<?php
/**
 * Automatic acknowledgement sent to the person who submitted an inquiry.
 * Variables: $inquiry (array), $files (attached uploads), $typeLabel (string), $reference (string), $contact (settings), $e (escaper).
 */
$whatsapp = preg_replace('/\D/', '', (string) ($contact['whatsapp'] ?? '923104489288'));
?>
<h1 style="margin:0 0 12px;font-size:20px;">Thank you, <?= $e($inquiry['name']) ?>.</h1>
<p style="margin:0 0 14px;">We have received your <?= $e(strtolower($typeLabel)) ?> and our team will get back to you soon.</p>
<p style="margin:0 0 14px;">Your reference number is <strong><?= $e($reference) ?></strong>. Please mention it if you contact us about this request.</p>
<p style="margin:0 0 20px;">For a faster reply, message us on WhatsApp:</p>
<p style="margin:0 0 24px;">
  <a href="https://wa.me/<?= $e($whatsapp) ?>" style="display:inline-block;background:#e57a2e;color:#070c1f;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Chat on WhatsApp</a>
</p>
<p style="margin:0;color:#5c6170;font-size:13px;">Your message:</p>
<p style="margin:6px 0 0;padding:12px 14px;background:#fbfaf7;border-radius:10px;font-size:14px;white-space:pre-line;"><?= $e($inquiry['message']) ?></p>
<?php if ($files !== []): ?>
<p style="margin:14px 0 0;color:#5c6170;font-size:13px;">Files we received: <?= $e(implode(', ', array_map(static fn (array $file): string => $file['name'], $files))) ?></p>
<?php endif; ?>
