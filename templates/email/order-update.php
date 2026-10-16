<?php
/**
 * Most order emails to the buyer: a title, a short text, key facts, optional bank details and a button.
 * Variables: $order (presented), $title, $intro (string), $facts (label => value), $paragraphs (list of strings),
 * $bank (array|null|false: false = no bank block), $button (array{label, url}|null), $e (escaper).
 */

$facts = $facts ?? [];
$paragraphs = $paragraphs ?? [];
$button = $button ?? null;
$showBank = isset($bank) && $bank !== false;
?>
<h1 style="margin:0 0 12px;font-size:20px;"><?= $e($title) ?></h1>
<p style="margin:0 0 6px;font-size:13px;color:#5c6170;">Order <strong style="font-family:monospace;color:#070c1f;"><?= $e($order['order_no']) ?></strong><?= $order['buyer_reference'] ? ' · your reference ' . $e($order['buyer_reference']) : '' ?></p>
<p style="margin:0 0 18px;"><?= $e($intro) ?></p>

<?php if ($facts !== []): ?>
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
    <?php foreach ($facts as $label => $value): ?>
      <?php if ($value !== null && $value !== ''): ?>
        <tr>
          <td style="padding:4px 14px 4px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;"><?= $e($label) ?></td>
          <td style="padding:4px 0;font-size:14px;"><strong><?= $e($value) ?></strong></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php foreach ($paragraphs as $paragraph): ?>
  <p style="margin:0 0 14px;"><?= $e($paragraph) ?></p>
<?php endforeach; ?>

<?php if ($showBank): ?>
  <?php require __DIR__ . '/partials/bank-details.php'; ?>
<?php endif; ?>

<?php if ($button !== null): ?>
  <p style="margin:4px 0 22px;">
    <a href="<?= $e($button['url']) ?>" style="display:inline-block;background:#e57a2e;color:#070c1f;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;"><?= $e($button['label']) ?></a>
  </p>
<?php endif; ?>

<p style="margin:0;font-size:13px;color:#5c6170;">Questions? Reply to this email or message us on WhatsApp.</p>
