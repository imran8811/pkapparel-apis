<?php
/**
 * Sent after signup and when a buyer asks for a new link.
 * Variables: $customer (row), $link (string), $hours (int), $e (escaper).
 */
?>
<h1 style="margin:0 0 12px;font-size:20px;">Confirm your email address</h1>
<p style="margin:0 0 14px;">Hello <?= $e($customer['contact_name']) ?>,</p>
<p style="margin:0 0 20px;">Thank you for opening a PK Apparel buyer account for <?= $e($customer['company_name']) ?>. Please confirm that this is your email address. You need a confirmed address to place orders.</p>
<p style="margin:0 0 24px;">
  <a href="<?= $e($link) ?>" style="display:inline-block;background:#e57a2e;color:#070c1f;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Confirm my email</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#5c6170;">The link works once and expires in <?= (int) $hours ?> hours. If the button does not work, copy this address into your browser:</p>
<p style="margin:0 0 16px;font-size:12px;word-break:break-all;color:#5c6170;"><?= $e($link) ?></p>
<p style="margin:0;font-size:13px;color:#5c6170;">Did not sign up? Then you can ignore this email.</p>
