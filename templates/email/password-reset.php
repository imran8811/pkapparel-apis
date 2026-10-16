<?php
/**
 * Sent when a buyer asks to reset their password.
 * Variables: $customer (row), $link (string), $minutes (int), $e (escaper).
 */
?>
<h1 style="margin:0 0 12px;font-size:20px;">Reset your password</h1>
<p style="margin:0 0 14px;">Hello <?= $e($customer['contact_name']) ?>,</p>
<p style="margin:0 0 20px;">We received a request to reset the password of your PK Apparel buyer account. Choose a new password here:</p>
<p style="margin:0 0 24px;">
  <a href="<?= $e($link) ?>" style="display:inline-block;background:#e57a2e;color:#070c1f;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Choose a new password</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#5c6170;">The link works once and expires in <?= (int) $minutes ?> minutes. If the button does not work, copy this address into your browser:</p>
<p style="margin:0 0 16px;font-size:12px;word-break:break-all;color:#5c6170;"><?= $e($link) ?></p>
<p style="margin:0;font-size:13px;color:#5c6170;">If you did not ask for this, ignore this email: your password stays the same.</p>
