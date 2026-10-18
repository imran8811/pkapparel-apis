<?php
/**
 * The morning summary for staff (bin/daily.php).
 * Variables: $date (string), $payments (list: order_no, company, kind, amount), $toPrice (sample orders to price: order_no,
 * company, placed), $late (list: order_no, company, due, days), $dueSoon (same), $cancelled (list: order_no, company),
 * $reminded (int), $reviews (int, waiting for approval), $links (admin, payments, orders), $e (escaper).
 */

use App\Core\Format;

$section = static function (string $title, array $rows, callable $line): string {
    if ($rows === []) {
        return '';
    }
    $items = implode('', array_map(static fn (array $row): string => '<li style="margin:0 0 4px;">' . $line($row) . '</li>', $rows));

    return '<h2 style="margin:18px 0 8px;font-size:16px;">' . htmlspecialchars($title) . ' (' . count($rows) . ')</h2><ul style="margin:0;padding-left:18px;font-size:14px;">' . $items . '</ul>';
};
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<h1 style="margin:0 0 6px;font-size:20px;">Good morning</h1>
<p style="margin:0 0 6px;color:#5c6170;font-size:13px;"><?= $e($date) ?></p>

<?= $section('Payments to check', $payments, static fn (array $p): string => '<strong>' . $h($p['order_no']) . '</strong> · ' . $h($p['company']) . ' · ' . $h($p['kind']) . ' ' . $h(Format::money($p['amount_cents']))) ?>
<?= $section('Sample orders waiting for a price', $toPrice ?? [], static fn (array $o): string => '<strong>' . $h($o['order_no']) . '</strong> · ' . $h($o['company']) . ' · placed ' . $h($o['placed'])) ?>
<?= $section('Late orders', $late, static fn (array $o): string => '<strong>' . $h($o['order_no']) . '</strong> · ' . $h($o['company']) . ' · due ' . $h(Format::date($o['due'])) . ' (' . $h(abs($o['days'])) . ' days late)') ?>
<?= $section('Due in the next 3 days', $dueSoon, static fn (array $o): string => '<strong>' . $h($o['order_no']) . '</strong> · ' . $h($o['company']) . ' · due ' . $h(Format::date($o['due']))) ?>
<?= $section('Cancelled because the payment did not arrive within 30 days', $cancelled, static fn (array $o): string => '<strong>' . $h($o['order_no']) . '</strong> · ' . $h($o['company'])) ?>

<?php if (($reviews ?? 0) > 0): ?>
  <p style="margin:18px 0 0;font-size:14px;"><?= (int) $reviews ?> buyer <?= $reviews === 1 ? 'review waits' : 'reviews wait' ?> for your approval (Buyer reviews in the admin).</p>
<?php endif; ?>
<?php if ($reminded > 0): ?>
  <p style="margin:18px 0 0;font-size:14px;"><?= (int) $reminded ?> payment <?= $reminded === 1 ? 'reminder was' : 'reminders were' ?> sent to buyers.</p>
<?php endif; ?>

<p style="margin:22px 0 0;">
  <a href="<?= $e($links['admin']) ?>" style="display:inline-block;background:#0b132b;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Open the admin</a>
</p>
