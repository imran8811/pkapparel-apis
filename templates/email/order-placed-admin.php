<?php
/**
 * Staff notification for a new order.
 * Variables: $order (presented), $links, $e (escaper).
 */

use App\Core\Format;

$customer = $order['customer'];
$address = $order['address'];
$isSample = ($order['kind'] ?? 'bulk') === 'sample';
?>
<h1 style="margin:0 0 12px;font-size:20px;"><?= $isSample ? 'New sample order' : 'New order' ?> <?= $e($order['order_no']) ?></h1>
<p style="margin:0 0 16px;">
  <strong><?= $e($customer['company_name']) ?></strong> (<?= $e(Format::country($customer['country_code'])) ?>)<br>
  <?= $e($customer['contact_name']) ?> · <a href="mailto:<?= $e($customer['email']) ?>" style="color:#0048ac;"><?= $e($customer['email']) ?></a> · <?= $e($customer['phone']) ?>
</p>
<p style="margin:0 0 16px;font-size:14px;">
  <?= (int) $order['total_pcs'] ?> pcs · <?= $e($order['shipping_mode_label']) ?> to <?= $e($address['city']) ?>, <?= $e(Format::country($address['country_code'])) ?><br>
  <?php if ($isSample && $order['status'] === 'awaiting_price'): ?>
    The buyer can pay once you send the price.
  <?php else: ?>
    <?= $isSample ? 'Payment due (in full)' : 'Deposit due' ?>: <strong><?= $e(Format::money($order['amounts']['deposit_cents'])) ?></strong>
  <?php endif; ?>
  <?php if ($order['buyer_reference']): ?><br>Buyer's reference: <?= $e($order['buyer_reference']) ?><?php endif; ?>
</p>
<?php if ($order['amounts']['has_quoted_addons'] || $order['amounts']['freight_status'] === 'quoted'): ?>
  <p style="margin:0 0 16px;padding:12px 14px;background:#fff4ea;border-radius:10px;font-size:14px;">
    <?php if ($isSample): ?>
      To price before the buyer can pay: <?= implode(' and ', array_filter([
          $order['amounts']['freight_status'] === 'quoted' ? 'the courier (no courier rate for this country)' : null,
          $order['amounts']['has_quoted_addons'] ? 'the custom-design sample (see the notes and the files on the order)' : null,
      ])) ?>. Enter the prices on the order page, then press "Send the price".
    <?php else: ?>
      To quote: <?= implode(' and ', array_filter([
          $order['amounts']['freight_status'] === 'quoted' ? 'the freight' : null,
          $order['amounts']['has_quoted_addons'] ? 'the add-ons marked "price on request"' : null,
      ])) ?>.
    <?php endif; ?>
  </p>
<?php endif; ?>
<?php require __DIR__ . '/partials/order-summary.php'; ?>
<?php if ($order['notes']): ?>
  <p style="margin:0 0 6px;color:#5c6170;font-size:13px;">Buyer's notes:</p>
  <p style="margin:0 0 18px;padding:12px 14px;background:#fbfaf7;border-radius:10px;font-size:14px;white-space:pre-line;"><?= $e($order['notes']) ?></p>
<?php endif; ?>
<p style="margin:0;">
  <a href="<?= $e($links['admin']) ?>" style="display:inline-block;background:#0b132b;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;">Open in the admin</a>
</p>
