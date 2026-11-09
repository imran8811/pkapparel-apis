<?php
/**
 * Where to pay a stock order: the bank account, JazzCash and Easypaisa (each only when set), or a note that the
 * details follow. Included by order emails. Variables: $stockPayment (array; empty until staff entered them), $e (escaper).
 */

$row = static fn (string $label, ?string $value): string => $value === null || $value === ''
    ? ''
    : '<tr><td style="padding:3px 12px 3px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($label) . '</td><td style="padding:3px 0;font-size:14px;"><strong>' . htmlspecialchars($value) . '</strong></td></tr>';
$heading = static fn (string $text): string => '<tr><td colspan="2" style="padding:10px 0 2px;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#9a4a12;">' . htmlspecialchars($text) . '</td></tr>';
$p = is_array($stockPayment ?? null) && $stockPayment !== [] ? $stockPayment : null;
?>
<?php if ($p !== null): ?>
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;padding:14px 16px;background:#fbfaf7;border:1px dashed #e6e0d4;border-radius:12px;width:100%;">
    <tr><td>
      <table role="presentation" cellpadding="0" cellspacing="0">
        <?php if (!empty($p['account_number']) || !empty($p['iban'])): ?>
          <?= $heading('Bank transfer') ?>
          <?= $row('Account title', $p['account_title'] ?? null) ?>
          <?= $row('Bank', $p['bank_name'] ?? null) ?>
          <?= $row('Account number', $p['account_number'] ?? null) ?>
          <?= $row('IBAN', $p['iban'] ?? null) ?>
        <?php endif; ?>
        <?php if (!empty($p['jazzcash_number'])): ?>
          <?= $heading('JazzCash') ?>
          <?= $row('Name', $p['jazzcash_name'] ?? null) ?>
          <?= $row('Number', $p['jazzcash_number'] ?? null) ?>
        <?php endif; ?>
        <?php if (!empty($p['easypaisa_number'])): ?>
          <?= $heading('Easypaisa') ?>
          <?= $row('Name', $p['easypaisa_name'] ?? null) ?>
          <?= $row('Number', $p['easypaisa_number'] ?? null) ?>
        <?php endif; ?>
      </table>
      <?php if (!empty($p['notes'])): ?><p style="margin:8px 0 0;font-size:13px;color:#5c6170;"><?= $e($p['notes']) ?></p><?php endif; ?>
    </td></tr>
  </table>
<?php else: ?>
  <p style="margin:0 0 20px;padding:12px 14px;background:#fff4ea;border-radius:10px;font-size:14px;">We will send you the payment details shortly.</p>
<?php endif; ?>
