<?php
/**
 * Shared email layout. Variables: $content (HTML), $preheader, $contact, $addresses (from settings), $e (escaper).
 * Inline styles only: email clients ignore stylesheets.
 */
$preheader = $preheader ?? '';
$contact = is_array($contact ?? null) ? $contact : [];
$factory = is_array($addresses ?? null) && isset($addresses[0]) ? $addresses[0] : null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PK Apparel</title>
</head>
<body style="margin:0;padding:0;background:#f5f2eb;font-family:Arial,Helvetica,sans-serif;color:#070c1f;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;"><?= $e($preheader) ?></span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f2eb;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;">
        <tr>
          <td style="background:#0b132b;padding:22px 28px;">
            <span style="font-size:22px;font-weight:bold;color:#ffffff;letter-spacing:-0.5px;">PK Apparel</span>
            <span style="display:block;margin-top:4px;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#f09a55;">Denim manufacturer · Made to order</span>
          </td>
        </tr>
        <tr>
          <td style="padding:28px;font-size:15px;line-height:1.6;">
            <?= $content ?>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 28px;background:#fbfaf7;border-top:1px dashed #e6e0d4;font-size:12px;line-height:1.6;color:#5c6170;">
            <?php if ($factory !== null): ?>
              PK Apparel · <?= $e($factory['line'] . ', ' . $factory['city'] . ', ' . $factory['country']) ?><br>
            <?php endif; ?>
            <?php if (!empty($contact['phone'])): ?>WhatsApp / phone: <?= $e($contact['phone']) ?> · <?php endif; ?>
            <a href="mailto:<?= $e($contact['email'] ?? 'info@pkapparel.com') ?>" style="color:#0048ac;"><?= $e($contact['email'] ?? 'info@pkapparel.com') ?></a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
