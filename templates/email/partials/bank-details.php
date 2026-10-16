<?php
/**
 * The bank account for transfers (or a note that the details follow), included by order emails.
 * Variables: $bank (array|null), $e (escaper).
 */

$row = static fn (string $label, ?string $value): string => $value === null || $value === ''
    ? ''
    : '<tr><td style="padding:3px 12px 3px 0;color:#5c6170;font-size:13px;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($label) . '</td><td style="padding:3px 0;font-size:14px;"><strong>' . htmlspecialchars($value) . '</strong></td></tr>';
?>
<?php if ($bank !== null): ?>
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;padding:14px 16px;background:#fbfaf7;border:1px dashed #e6e0d4;border-radius:12px;width:100%;">
    <tr><td>
      <table role="presentation" cellpadding="0" cellspacing="0">
        <?= $row('Beneficiary', $bank['beneficiary'] ?? null) ?>
        <?= $row('Bank', $bank['bank_name'] ?? null) ?>
        <?= $row('Branch', $bank['branch'] ?? null) ?>
        <?= $row('Account number', $bank['account_number'] ?? null) ?>
        <?= $row('IBAN', $bank['iban'] ?? null) ?>
        <?= $row('SWIFT / BIC', $bank['swift'] ?? null) ?>
        <?= $row('Bank address', $bank['bank_address'] ?? null) ?>
      </table>
      <?php if (!empty($bank['notes'])): ?><p style="margin:8px 0 0;font-size:13px;color:#5c6170;"><?= $e($bank['notes']) ?></p><?php endif; ?>
    </td></tr>
  </table>
<?php else: ?>
  <p style="margin:0 0 20px;padding:12px 14px;background:#fff4ea;border-radius:10px;font-size:14px;">Our team will send you the bank details for the transfer shortly.</p>
<?php endif; ?>
