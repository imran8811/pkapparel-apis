<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Product;
use App\Models\ProductColor;
use PDOException;
use Throwable;

/**
 * Sample orders (owner decision 2026-10-17). The buyer picks styles, colors and sizes, and pays the samples plus
 * the courier in full by bank transfer before dispatch; payment reports, receipts and tracking work as for bulk orders.
 *
 * The courier comes from the courier rate of the buyer's shipping zone. When there is none, or the order includes a
 * custom-design sample, the order waits for staff to price it (status awaiting_price): staff enter the courier and
 * the custom sample's price on the order (OrderWorkflow::setCharges), then send the price (sendPrice).
 */
final class SampleOrders
{
    /** Pieces per sample order. */
    public const MAX_PIECES = 10;

    /** Pieces of one color in one size. */
    public const MAX_PER_SIZE = 3;

    /** Packaging added to the samples' weight (courier bag or box). */
    private const PACKAGING_G = 300;

    public function __construct(
        private readonly Database $db,
        private readonly Order $orders,
        private readonly Product $products,
        private readonly ProductColor $colors,
        private readonly Catalog $catalog,
        private readonly CustomerAddress $addresses,
        private readonly Freight $freight,
        private readonly Commerce $commerce,
        private readonly InquiryAttachments $attachments,
        private readonly OrderDocument $documents,
        private readonly OrderService $service,
        private readonly OrderMailer $mailer,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Prices a sample choice for the buyer's address (the sample order page).
     *
     * @param array<string, mixed> $customer database row
     * @param array<mixed> $items [{color_id, size, qty}]
     * @param array<string, mixed>|null $custom a custom-design sample {product, description, qty}
     * @param string|null $country the destination of an address not saved yet (instead of a saved address)
     * @return array<string, mixed>
     */
    public function quote(array $customer, array $items, ?array $custom, ?int $addressId, ?string $country = null): array
    {
        $lines = $this->lines($items);
        $custom = self::custom($custom);
        $pieces = array_sum(array_column($lines, 'qty')) + ($custom['qty'] ?? 0);
        if ($pieces === 0) {
            throw new ValidationException(['items' => 'Choose at least one sample.']);
        }
        if ($pieces > self::MAX_PIECES) {
            throw new ValidationException(['items' => sprintf('A sample order can have up to %d pieces.', self::MAX_PIECES)]);
        }

        $addresses = $this->addresses->forCustomer((int) $customer['id']);
        $selected = null;
        foreach ($addresses as $address) {
            if ($address['id'] === $addressId || ($addressId === null && $selected === null)) {
                $selected = $address;
            }
        }
        $country = $country ?? $selected['country_code'] ?? (string) $customer['country_code'];
        $courier = $this->freight->courier($country, $pieces);
        $goods = array_sum(array_column($lines, 'line_cents'));
        $needsPrice = $custom !== null || $courier['cost_cents'] === null;

        return [
            'lines' => $lines,
            'custom' => $custom,
            'pieces' => $pieces,
            'goods_cents' => $goods,
            'courier' => $courier,
            'total_cents' => $needsPrice ? null : $goods + (int) $courier['cost_cents'],
            'needs_price' => $needsPrice,
            'lead_time_days' => $custom !== null ? $this->commerce->customSampleLeadTimeDays() : $this->commerce->sampleLeadTimeDays(),
            'addresses' => $addresses,
            'address_id' => $selected['id'] ?? null,
            'country_code' => $country,
            'email_verified' => $customer['email_verified_at'] !== null,
        ];
    }

    /**
     * Places a sample order.
     *
     * @param array<string, mixed> $customer database row
     * @param array<string, mixed> $data validated input: items, custom, address_id, notes, buyer_reference, expected_total_cents
     * @return array<string, mixed> the new order (database row)
     */
    public function place(array $customer, array $data): array
    {
        if ($customer['email_verified_at'] === null) {
            throw new HttpException(403, 'email_unverified', 'Please confirm your email address before placing an order. We sent you a link.');
        }
        $address = $this->addresses->find((int) $customer['id'], (int) $data['address_id']);
        if ($address === null) {
            throw new ValidationException(['address_id' => 'Choose a delivery address.']);
        }
        $quote = $this->quote($customer, $data['items'] ?? [], $data['custom'] ?? null, (int) $address['id']);
        if (!$quote['needs_price'] && isset($data['expected_total_cents']) && (int) $data['expected_total_cents'] !== $quote['total_cents']) {
            throw new HttpException(409, 'totals_changed', 'Prices or the courier cost changed since you opened this page. Please review the new total and place the order again.');
        }
        $files = $quote['custom'] !== null ? $this->attachments->resolve($data['custom']['files'] ?? [], 'custom.files') : [];

        $orderId = null;
        for ($attempt = 1; $orderId === null; $attempt++) {
            try {
                $orderId = $this->db->transaction(fn (): int => $this->create($customer, $address, $quote, $files, $data));
            } catch (PDOException $e) {
                // Two orders got the same random number at the same moment: try another one.
                if ($attempt >= 3 || !str_contains($e->getMessage(), 'orders_order_no')) {
                    throw $e;
                }
            }
        }

        $order = $this->orders->find($orderId) ?? throw new \RuntimeException('The new sample order could not be read back.');
        try {
            $this->mailer->samplePlaced($this->service->present($order, false), $this->service->present($order, true));
        } catch (Throwable $e) {
            $this->logger->error('Sample order emails could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
        }

        return $order;
    }

    /**
     * Staff priced a sample order (courier and custom sample): it becomes payable in full and the buyer gets the invoice.
     *
     * @param array<string, mixed> $order database row
     * @param array{id: int} $staff
     */
    public function sendPrice(array $order, array $staff): void
    {
        if ($order['kind'] !== 'sample' || $order['status'] !== 'awaiting_price') {
            throw new HttpException(409, 'wrong_status', 'This order is not waiting for a price.');
        }
        $amounts = OrderService::amounts($order, $this->orders->addons($order['id']));
        if ($amounts['freight_cents'] === null) {
            throw new HttpException(409, 'courier_missing', 'Enter the courier cost first.');
        }
        if ($amounts['has_quoted_addons']) {
            throw new HttpException(409, 'addons_unpriced', 'Enter the price of the custom-design sample first.');
        }

        $changed = $this->orders->changeStatus($order['id'], 'awaiting_price', 'awaiting_deposit', 'staff', $staff['id'], 'Price sent: ' . Format::money($amounts['total_cents']), [
            'deposit_cents' => $amounts['total_cents'],
            'priced_at' => gmdate('Y-m-d H:i:s'),
            'reminders_sent' => 0,
        ]);
        if (!$changed) {
            throw new HttpException(409, 'wrong_status', 'This order changed in the meantime. Reload the page.');
        }

        $fresh = $this->orders->find($order['id']);
        if ($fresh !== null) {
            try {
                $this->mailer->samplePriced($this->service->present($fresh, false));
            } catch (Throwable $e) {
                $this->logger->error('Sample invoice email could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * A bulk order was confirmed: the cost of the buyer's paid samples (without the courier) is deducted from its
     * balance, once (owner decision 2026-10-17). Call it inside the transaction that confirms the order.
     *
     * @param array<string, mixed> $order the bulk order (database row)
     * @return int the cents deducted
     */
    public function creditTo(array $order, ?int $staffId): int
    {
        if ($order['kind'] !== 'bulk') {
            return 0;
        }
        $samples = $this->orders->uncreditedSamples($order['customer_id'], true);
        if ($samples === []) {
            return 0;
        }
        $this->orders->creditSamples($order['id'], $samples);
        $cents = array_sum(array_column($samples, 'cost_cents'));
        $this->orders->addEvent($order['id'], 'credit', null, null, sprintf(
            'Samples (%s) deducted from the balance: %s',
            implode(', ', array_column($samples, 'order_no')),
            Format::money($cents),
        ), 'staff', $staffId);
        foreach ($samples as $sample) {
            $this->orders->addEvent($sample['id'], 'credit', null, null, sprintf('Sample cost deducted from order %s', $order['order_no']), 'staff', $staffId);
        }

        return $cents;
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $address
     * @param array<string, mixed> $quote
     * @param list<array<string, mixed>> $files resolved uploads of a custom-design sample
     * @param array<string, mixed> $data
     */
    private function create(array $customer, array $address, array $quote, array $files, array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $courier = $quote['courier']['cost_cents'];
        $custom = $quote['custom'];
        $status = $quote['needs_price'] ? 'awaiting_price' : 'awaiting_deposit';
        $notes = trim(implode("\n\n", array_filter([
            $custom !== null ? 'Custom-design sample: ' . $custom['description'] : null,
            $data['notes'] ?? null,
        ])));

        $grams = 0;
        foreach ($quote['lines'] as $line) {
            $grams += $line['qty'] * $line['piece_weight_g'];
        }
        $grams += ($custom['qty'] ?? 0) * Commerce::PACKING_DEFAULTS['piece_weight_g'] + self::PACKAGING_G;

        $id = $this->orders->create([
            'order_no' => $this->service->nextOrderNo(),
            'kind' => 'sample',
            'customer_id' => (int) $customer['id'],
            'status' => $status,
            'currency' => 'USD',
            'total_pcs' => $quote['pieces'],
            'goods_cents' => $quote['goods_cents'],
            'addons_cents' => 0,
            'shipping_mode' => 'courier',
            'incoterm' => 'DAP',
            // A courier rate is a price, not an estimate.
            'freight_estimate_cents' => $courier,
            'freight_final_cents' => $courier,
            'deposit_percent' => 100,
            'deposit_cents' => $quote['needs_price'] ? 0 : (int) $quote['total_cents'],
            'lead_time_days' => $quote['lead_time_days'],
            'packing' => ['cartons' => 1, 'gross_g' => $grams, 'volume_cm3' => 0, 'cbm' => 0.0, 'freight' => $quote['courier']],
            'customer' => [
                'company_name' => $customer['company_name'],
                'contact_name' => $customer['contact_name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
                'country_code' => $customer['country_code'],
                'business_type' => $customer['business_type'],
            ],
            'address' => array_diff_key($address, ['id' => true, 'is_default' => true]),
            'forwarder' => null,
            'buyer_reference' => $data['buyer_reference'] ?? null,
            'notes' => $notes === '' ? null : $notes,
            'placed_at' => $now,
            'priced_at' => $quote['needs_price'] ? null : $now,
        ], array_map(static fn (array $line): array => array_diff_key($line, ['piece_weight_g' => true]), $quote['lines']), $custom === null ? [] : [[
            'option_id' => null,
            'name' => mb_substr('Custom-design sample' . ($custom['product'] !== null ? ': ' . $custom['product'] : ''), 0, 120),
            'pricing' => 'on_request',
            'unit_price_cents' => null,
            'qty' => $custom['qty'],
            'line_cents' => null,
        ]]);

        $this->orders->addEvent($id, 'placed', null, $status, null, 'customer', (int) $customer['id']);
        // The buyer's design files become documents of the order (the buyer sees them too).
        foreach ($files as $file) {
            $this->documents->create($id, [
                'type' => 'design',
                'title' => mb_substr(pathinfo($file['name'], PATHINFO_FILENAME) ?: 'Design file', 0, 160),
                'path' => $file['path'],
                'mime' => $file['mime'],
                'size_bytes' => $file['size_bytes'],
                'is_public' => 1,
                'uploaded_by' => null,
            ]);
            $this->attachments->release($file);
        }

        return $id;
    }

    /**
     * One order line per color, with the pieces per size, priced at the style's sample price.
     *
     * @param array<mixed> $items [{color_id, size, qty}]
     * @return list<array<string, mixed>>
     */
    private function lines(array $items): array
    {
        if (count($items) > self::MAX_PIECES) {
            throw new ValidationException(['items' => sprintf('A sample order can have up to %d pieces.', self::MAX_PIECES)]);
        }
        $byColor = [];
        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $size = trim((string) ($item['size'] ?? ''));
            $qty = (int) ($item['qty'] ?? 0);
            $color = $this->colors->find((int) ($item['color_id'] ?? 0));
            $product = $color === null ? null : $this->products->find($color['product_id']);
            if ($color === null || !$color['is_active'] || $product === null || $product['status'] !== 'active') {
                throw new ValidationException(['items' => 'One of the samples is no longer available. Please remove it.']);
            }
            if (!in_array($size, $product['sizes'], true)) {
                throw new ValidationException(['items' => sprintf('Size %s does not exist for %s.', $size, $product['name'])]);
            }
            $byColor[$color['id']] ??= ['color' => $color, 'product' => $product, 'sizes' => []];
            $byColor[$color['id']]['sizes'][$size] = ($byColor[$color['id']]['sizes'][$size] ?? 0) + $qty;
            if ($qty < 1 || $byColor[$color['id']]['sizes'][$size] > self::MAX_PER_SIZE) {
                throw new ValidationException(['items' => sprintf('Choose 1 to %d pieces of each color and size.', self::MAX_PER_SIZE)]);
            }
        }

        $productIds = array_values(array_unique(array_map(static fn (array $entry): int => $entry['product']['id'], $byColor)));
        $images = [];
        foreach ($this->colors->forProducts($productIds, false, 1) as $colors) {
            foreach ($colors as $color) {
                $images[$color['id']] = $color['images'][0]['path'] ?? null;
            }
        }

        $lines = [];
        foreach ($byColor as $colorId => $entry) {
            $product = $entry['product'];
            $sizeQty = [];
            foreach ($product['sizes'] as $size) {
                if (isset($entry['sizes'][$size])) {
                    $sizeQty[$size] = $entry['sizes'][$size];
                }
            }
            $qty = array_sum($sizeQty);
            $unit = $this->catalog->rules($product)['sample_price_cents'];
            $lines[] = [
                'product_id' => $product['id'],
                'color_id' => $colorId,
                'style_no' => $product['style_no'],
                'style_name' => $product['name'],
                'style_slug' => $product['slug'],
                'segment' => $product['segment'],
                'product_type' => $product['product_type'],
                'color_code' => $entry['color']['code'],
                'color_name' => $entry['color']['name'],
                'color_hex' => $entry['color']['hex'],
                'image_path' => $images[$colorId] ?? null,
                'size_qty' => $sizeQty,
                'qty' => $qty,
                'unit_price_cents' => $unit,
                'line_cents' => $unit * $qty,
                'piece_weight_g' => $product['piece_weight_g'] ?? Commerce::PACKING_DEFAULTS['piece_weight_g'],
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed>|null $custom
     * @return array{product: ?string, description: string, qty: int}|null
     */
    private static function custom(?array $custom): ?array
    {
        if ($custom === null || $custom === []) {
            return null;
        }
        $description = trim((string) ($custom['description'] ?? ''));
        $qty = (int) ($custom['qty'] ?? 1);
        $product = trim((string) ($custom['product'] ?? ''));
        if (mb_strlen($description) < 10) {
            throw new ValidationException(['custom.description' => 'Describe the sample you need (fabric, wash, fit, sizes), or attach your tech pack.']);
        }
        if ($qty < 1 || $qty > self::MAX_PER_SIZE) {
            throw new ValidationException(['custom.qty' => sprintf('Choose 1 to %d pieces.', self::MAX_PER_SIZE)]);
        }

        return ['product' => $product === '' ? null : mb_substr($product, 0, 60), 'description' => mb_substr($description, 0, 2000), 'qty' => $qty];
    }
}
