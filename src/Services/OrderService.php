<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\CustomizationOption;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderPayment;
use App\Models\OrderPhoto;
use App\Models\OrderStage;
use App\Models\Settings;
use App\Models\Shipment;
use PDOException;
use Throwable;

/**
 * Checkout, placing orders, the order view, cancelling and payment reports.
 *
 * Money rules (owner decision 2026-10-16): the deposit is a percentage (50%) of the goods and the
 * priced add-ons. Freight is an estimate until staff confirm it, and is paid with the balance,
 * together with any "price on request" add-ons.
 */
final class OrderService
{
    public function __construct(
        private readonly Database $db,
        private readonly Order $orders,
        private readonly OrderPayment $payments,
        private readonly Cart $carts,
        private readonly CartService $cartService,
        private readonly CustomerAddress $addresses,
        private readonly CustomizationOption $options,
        private readonly Freight $freight,
        private readonly Commerce $commerce,
        private readonly Settings $settings,
        private readonly PaymentSlips $slips,
        private readonly OrderMailer $mailer,
        private readonly Logger $logger,
        private readonly OrderStage $stages,
        private readonly OrderPhoto $photos,
        private readonly OrderDocument $documents,
        private readonly Shipment $shipments,
    ) {
    }

    /**
     * Everything the checkout page needs, with freight estimates for the chosen address (or country).
     *
     * @param array<string, mixed> $customer database row
     * @return array<string, mixed>
     */
    public function checkout(array $customer, ?int $addressId, ?string $country): array
    {
        $cart = $this->cartService->summary($this->carts->idForCustomer((int) $customer['id']));
        $addresses = $this->addresses->forCustomer((int) $customer['id']);
        $selected = null;
        foreach ($addresses as $address) {
            if ($address['id'] === $addressId || ($addressId === null && $selected === null)) {
                $selected = $address;
            }
        }
        $country ??= $selected['country_code'] ?? (string) $customer['country_code'];

        return [
            'cart' => $cart,
            'addresses' => $addresses,
            'address_id' => $selected['id'] ?? null,
            'country_code' => $country,
            'shipping' => $this->freight->quote($country, $cart['packing']),
            'addons' => $this->options->active(),
            'deposit_percent' => $this->commerce->depositPercent(),
            'email_verified' => $customer['email_verified_at'] !== null,
        ];
    }

    /**
     * Turns the buyer's cart into an order (checked again against the live catalog) and empties the cart.
     *
     * @param array<string, mixed> $customer database row
     * @param array<string, mixed> $data validated checkout input
     * @return array<string, mixed> the new order (database row)
     */
    public function place(array $customer, array $data): array
    {
        $customerId = (int) $customer['id'];
        if ($customer['email_verified_at'] === null) {
            throw new HttpException(403, 'email_unverified', 'Please confirm your email address before placing an order. We sent you a link.');
        }
        $address = $this->addresses->find($customerId, (int) $data['address_id']);
        if ($address === null) {
            throw new ValidationException(['address_id' => 'Choose a delivery address.']);
        }
        $chosenAddons = $this->chosenAddons($data['addons'] ?? []);

        $orderId = null;
        for ($attempt = 1; $orderId === null; $attempt++) {
            try {
                $orderId = $this->db->transaction(fn (): int => $this->createOrder($customer, $address, $chosenAddons, $data));
            } catch (PDOException $e) {
                // Two orders got the same random number at the same moment: try another one.
                if ($attempt >= 3 || !str_contains($e->getMessage(), 'orders_order_no')) {
                    throw $e;
                }
            }
        }

        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new \RuntimeException('The new order could not be read back.');
        }
        try {
            $this->mailer->placed($this->present($order, false), $this->present($order, true));
        } catch (Throwable $e) {
            $this->logger->error('Order emails could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $chosenAddons
     * @param array<string, mixed> $data
     */
    private function createOrder(array $customer, array $address, array $chosenAddons, array $data): int
    {
        $customerId = (int) $customer['id'];
        $cartId = $this->carts->idForCustomer($customerId);
        if ($cartId !== null) {
            // Lock the cart, so a double click cannot place the same cart twice.
            $this->db->fetch('SELECT id FROM carts WHERE id = ? FOR UPDATE', [$cartId]);
        }
        $cart = $this->cartService->summary($cartId);
        if ($cart['line_count'] === 0) {
            throw new HttpException(409, 'cart_empty', 'Your cart is empty.');
        }
        if (!$cart['can_checkout']) {
            throw new HttpException(409, 'cart_invalid', 'Please check your cart first. ' . implode(' ', $cart['issues']));
        }

        $mode = (string) $data['shipping_mode'];
        $quote = $this->freight->quote($address['country_code'], $cart['packing'])[$mode];
        $freight = $mode === 'forwarder' ? null : $quote['cost_cents'];

        $addonLines = [];
        foreach ($chosenAddons as $option) {
            $qty = $option['pricing'] === 'per_piece' ? $cart['total_pcs'] : 1;
            $addonLines[] = [
                'option_id' => $option['id'],
                'name' => $option['name'],
                'pricing' => $option['pricing'],
                'unit_price_cents' => $option['price_cents'],
                'qty' => $qty,
                'line_cents' => $option['price_cents'] === null ? null : $option['price_cents'] * $qty,
            ];
        }
        $addonsCents = array_sum(array_map(static fn (array $a): int => (int) $a['line_cents'], $addonLines));

        $goods = $cart['goods_cents'];
        $total = $goods + $addonsCents + ($freight ?? 0);
        if (isset($data['expected_total_cents']) && (int) $data['expected_total_cents'] !== $total) {
            throw new HttpException(409, 'totals_changed', 'Prices or freight changed since you opened checkout. Please review the new totals and place the order again.');
        }
        $percent = $this->commerce->depositPercent();

        $lines = [];
        foreach ($cart['styles'] as $style) {
            foreach ($style['colors'] as $color) {
                $lines[] = [
                    'product_id' => $style['product_id'],
                    'color_id' => $color['color_id'],
                    'style_no' => $style['style_no'],
                    'style_name' => $style['name'],
                    'style_slug' => $style['slug'],
                    'segment' => $style['segment'],
                    'product_type' => $style['product_type'],
                    'color_code' => $color['code'],
                    'color_name' => $color['name'],
                    'color_hex' => $color['hex'],
                    'image_path' => $color['image']['path'] ?? null,
                    'size_qty' => (array) $color['size_qty'],
                    'qty' => $color['pcs'],
                    'unit_price_cents' => $color['unit_price_cents'],
                    'line_cents' => $color['line_cents'],
                ];
            }
        }

        $forwarder = null;
        if ($mode === 'forwarder' && (($data['forwarder_name'] ?? null) !== null || ($data['forwarder_contact'] ?? null) !== null)) {
            $forwarder = ['name' => $data['forwarder_name'] ?? null, 'contact' => $data['forwarder_contact'] ?? null];
        }

        $id = $this->orders->create([
            'order_no' => $this->newOrderNo(),
            'customer_id' => $customerId,
            'status' => 'awaiting_deposit',
            'currency' => 'USD',
            'total_pcs' => $cart['total_pcs'],
            'goods_cents' => $goods,
            'addons_cents' => $addonsCents,
            'shipping_mode' => $mode,
            'incoterm' => $mode === 'forwarder' ? 'FOB' : 'DAP',
            'freight_estimate_cents' => $freight,
            'deposit_percent' => $percent,
            'deposit_cents' => (int) round(($goods + $addonsCents) * $percent / 100),
            'lead_time_days' => $cart['lead_time_days'],
            'packing' => $cart['packing'] + ['freight' => $mode === 'forwarder' ? null : $quote],
            'customer' => [
                'company_name' => $customer['company_name'],
                'contact_name' => $customer['contact_name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
                'country_code' => $customer['country_code'],
                'business_type' => $customer['business_type'],
            ],
            'address' => array_diff_key($address, ['id' => true, 'is_default' => true]),
            'forwarder' => $forwarder,
            'buyer_reference' => $data['buyer_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'placed_at' => gmdate('Y-m-d H:i:s'),
        ], $lines, $addonLines);

        $this->orders->addEvent($id, 'placed', null, 'awaiting_deposit', null, 'customer', $customerId);
        $this->carts->clear((int) $cartId);

        return $id;
    }

    /**
     * The order as the buyer (or staff) sees it, with amounts, payments, timeline and what can be done next.
     *
     * @param array<string, mixed> $order database row
     * @return array<string, mixed>
     */
    public function present(array $order, bool $forStaff): array
    {
        $payments = $this->payments->forOrder($order['id']);
        $amounts = self::amounts($order, $this->orders->addons($order['id']));
        $nextKind = self::nextPaymentKind($order['status']);
        $canPay = $nextKind !== null && $amounts['due_now_cents'] > 0;
        $placed = Format::local($order['placed_at']);

        $presented = [
            'order_no' => $order['order_no'],
            'status' => $order['status'],
            'status_label' => self::statusLabel($order['status'], $amounts),
            'placed_at' => $order['placed_at'],
            'valid_until' => $placed->modify('+' . $this->commerce->piValidDays() . ' days')->format('Y-m-d'),
            'currency' => $order['currency'],
            'shipping_mode' => $order['shipping_mode'],
            'shipping_mode_label' => Order::MODES[$order['shipping_mode']] ?? $order['shipping_mode'],
            'incoterm' => $order['incoterm'],
            'buyer_reference' => $order['buyer_reference'],
            'notes' => $order['notes'],
            'customer' => $order['customer'],
            'address' => $order['address'],
            'forwarder' => $order['forwarder'],
            'total_pcs' => $order['total_pcs'],
            'lead_time_days' => $order['lead_time_days'],
            'packing' => $order['packing'],
            'lines' => $this->orders->lines($order['id']),
            'addons' => $this->orders->addons($order['id']),
            'amounts' => $amounts,
            'payments' => array_map(static fn (array $p): array => self::presentPayment($p, $forStaff), $payments),
            'events' => $this->orders->events($order['id'], !$forStaff),
            'next_payment_kind' => $canPay ? $nextKind : null,
            'can_submit_payment' => $canPay,
            'can_cancel' => self::canCancel($order, $payments),
            'bank' => !$forStaff && $canPay ? $this->bank() : null,
            'confirmed_at' => $order['confirmed_at'],
            'production_due_on' => $order['production_due_on'],
            'production_days_left' => self::daysLeft($order),
            'stages' => OrderStage::timeline($this->stages->forOrder($order['id'])),
            'photos' => array_map(static fn (array $photo): array => OrderPhoto::present($photo), $this->photos->forOrder($order['id'])),
            'documents' => $this->documentList($order, $payments, $forStaff),
            'shipment' => $this->shipments->forOrder($order['id']),
            'balance_invoice_no' => $order['balance_invoice_no'],
            'balance_invoiced_at' => $order['balance_invoiced_at'],
            'shipped_at' => $order['shipped_at'],
            'delivered_at' => $order['delivered_at'],
            'cancelled_at' => $order['cancelled_at'],
            'cancel_reason' => $order['cancel_reason'],
        ];
        if ($forStaff) {
            $presented = ['id' => $order['id'], 'customer_id' => $order['customer_id']] + $presented + ['status_before_hold' => $order['status_before_hold']];
        }

        return $presented;
    }

    /**
     * One row of an order list (buyer's account or admin).
     *
     * @param array<string, mixed> $order database row with line summaries (Order::forCustomer / adminList)
     * @return array<string, mixed>
     */
    public static function summary(array $order, bool $forStaff): array
    {
        $amounts = self::amounts($order, []);
        $summary = [
            'order_no' => $order['order_no'],
            'status' => $order['status'],
            'status_label' => self::statusLabel($order['status'], $amounts),
            'placed_at' => $order['placed_at'],
            'total_pcs' => $order['total_pcs'],
            'shipping_mode' => $order['shipping_mode'],
            'shipping_mode_label' => Order::MODES[$order['shipping_mode']] ?? $order['shipping_mode'],
            'styles' => $order['styles'] ?? [],
            'color_count' => $order['color_count'] ?? 0,
            'image_path' => $order['image_path'] ?? null,
            'total_cents' => $amounts['total_cents'],
            'freight_status' => $amounts['freight_status'],
            'due_now_cents' => $amounts['due_now_cents'],
            'buyer_reference' => $order['buyer_reference'],
        ];
        if ($forStaff) {
            $summary = ['id' => $order['id'], 'customer_id' => $order['customer_id']] + $summary + [
                'company_name' => $order['customer']['company_name'] ?? '',
                'country_code' => $order['address']['country_code'] ?? ($order['customer']['country_code'] ?? ''),
            ];
        }

        return $summary;
    }

    /**
     * Totals: the deposit is due first, the balance (with the final freight) before shipment.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $addons
     * @return array<string, mixed>
     */
    public static function amounts(array $order, array $addons): array
    {
        $final = $order['freight_final_cents'];
        $estimate = $order['freight_estimate_cents'];
        $freight = $final ?? $estimate;
        $freightStatus = match (true) {
            $order['shipping_mode'] === 'forwarder' => 'none',
            $final !== null => 'final',
            $estimate !== null => 'estimate',
            default => 'quoted',
        };
        $total = $order['goods_cents'] + $order['addons_cents'] + ($freight ?? 0);
        $paid = $order['paid_cents'];
        $dueNow = match (self::nextPaymentKind($order['status'])) {
            'deposit' => max(0, $order['deposit_cents'] - $paid),
            'balance' => max(0, $total - $paid),
            default => 0,
        };

        return [
            'goods_cents' => $order['goods_cents'],
            'addons_cents' => $order['addons_cents'],
            'has_quoted_addons' => array_filter($addons, static fn (array $a): bool => $a['line_cents'] === null) !== [],
            'freight_cents' => $freight,
            'freight_status' => $freightStatus,
            'total_cents' => $total,
            'deposit_percent' => $order['deposit_percent'],
            'deposit_cents' => $order['deposit_cents'],
            'balance_cents' => max(0, $total - $order['deposit_cents']),
            'paid_cents' => $paid,
            'due_now_cents' => $dueNow,
        ];
    }

    /**
     * The buyer cancels an order before paying anything.
     *
     * @param array<string, mixed> $order
     */
    public function cancelByCustomer(array $order, int $customerId, ?string $reason): void
    {
        if (!self::canCancel($order, $this->payments->forOrder($order['id']))) {
            throw new HttpException(409, 'cannot_cancel', 'This order can no longer be cancelled online. Please contact us.');
        }
        $changed = $this->orders->changeStatus($order['id'], 'awaiting_deposit', 'cancelled', 'customer', $customerId, $reason, [
            'cancelled_at' => gmdate('Y-m-d H:i:s'),
            'cancel_reason' => $reason,
        ]);
        if (!$changed) {
            throw new HttpException(409, 'cannot_cancel', 'This order can no longer be cancelled online. Please contact us.');
        }

        $fresh = $this->orders->find($order['id']);
        if ($fresh !== null) {
            try {
                $this->mailer->cancelled($this->present($fresh, true));
            } catch (Throwable $e) {
                $this->logger->error('Cancellation email could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Records a bank transfer reported by the buyer; staff check it against the bank statement (Phase 4).
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $data validated input
     * @param array<string, mixed>|null $slip uploaded file ($_FILES entry)
     * @return array<string, mixed> the payment
     */
    public function submitPayment(array $order, int $customerId, array $data, ?array $slip): array
    {
        $kind = self::nextPaymentKind($order['status']);
        $amounts = self::amounts($order, $this->orders->addons($order['id']));
        if ($kind === null || $amounts['due_now_cents'] <= 0) {
            throw new HttpException(409, 'payment_not_due', 'No payment is due for this order right now.');
        }

        $placedOn = Format::local($order['placed_at'])->format('Y-m-d');
        $tomorrow = Format::local()->modify('+1 day')->format('Y-m-d');
        $errors = [];
        if ($data['transferred_on'] > $tomorrow) {
            $errors['transferred_on'] = 'The transfer date cannot be in the future.';
        } elseif ($data['transferred_on'] < (new \DateTimeImmutable($placedOn))->modify('-1 day')->format('Y-m-d')) {
            $errors['transferred_on'] = 'The transfer date cannot be before the order date (' . Format::date($placedOn) . ').';
        }
        if ($this->payments->transactionUsed($data['transaction_id'])) {
            $errors['transaction_id'] = 'This transaction ID has already been reported. Check the number, or contact us if you think this is a mistake.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $file = $this->slips->store($slip);
        try {
            $paymentId = $this->db->transaction(function () use ($order, $customerId, $data, $kind, $file): int {
                $id = $this->payments->create($order['id'], [
                    'kind' => $kind,
                    'amount_cents' => $data['amount_cents'],
                    'currency' => $order['currency'],
                    'transaction_id' => $data['transaction_id'],
                    'transferred_on' => $data['transferred_on'],
                    'payer_name' => $data['payer_name'],
                    'payer_bank' => $data['payer_bank'],
                    'slip_path' => $file['path'] ?? null,
                    'slip_name' => $file['name'] ?? null,
                    'slip_type' => $file['type'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);
                $note = sprintf('%s of %s reported, transaction %s', OrderPayment::KINDS[$kind], Format::money($data['amount_cents']), $data['transaction_id']);
                if ($order['status'] === 'awaiting_deposit') {
                    $this->orders->changeStatus($order['id'], 'awaiting_deposit', 'payment_review', 'customer', $customerId, $note);
                } else {
                    $this->orders->addEvent($order['id'], 'payment_submitted', null, null, $note, 'customer', $customerId);
                }

                return $id;
            });
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'order_payments_transaction')) {
                throw new ValidationException(['transaction_id' => 'This transaction ID has already been reported.']);
            }
            throw $e;
        }

        $payment = $this->payments->find($paymentId);
        $fresh = $this->orders->find($order['id']);
        if ($payment !== null && $fresh !== null) {
            try {
                $this->mailer->paymentSubmitted($this->present($fresh, false), $this->present($fresh, true), self::presentPayment($payment, true));
            } catch (Throwable $e) {
                $this->logger->error('Payment emails could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
            }
        }

        return self::presentPayment((array) $payment, false);
    }

    /** @return array<string, mixed>|null Bank account for transfers, or null until staff have entered it. */
    public function bank(): ?array
    {
        $bank = $this->settings->get('bank', []);
        if (!is_array($bank) || (trim((string) ($bank['account_number'] ?? '')) === '' && trim((string) ($bank['iban'] ?? '')) === '')) {
            return null;
        }

        return $bank;
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public static function presentPayment(array $payment, bool $forStaff): array
    {
        $presented = [
            'kind' => $payment['kind'],
            'kind_label' => OrderPayment::KINDS[$payment['kind']] ?? $payment['kind'],
            'amount_cents' => $payment['amount_cents'],
            'currency' => $payment['currency'],
            'transaction_id' => $payment['transaction_id'],
            'transferred_on' => $payment['transferred_on'],
            'payer_name' => $payment['payer_name'],
            'payer_bank' => $payment['payer_bank'],
            'has_slip' => $payment['slip_path'] !== null,
            'notes' => $payment['notes'],
            'status' => $payment['status'],
            'status_label' => OrderPayment::STATUSES[$payment['status']] ?? $payment['status'],
            'rejection_reason' => $payment['rejection_reason'],
            'receipt_no' => $payment['receipt_no'],
            'received_cents' => $payment['received_cents'],
            'received_on' => $payment['received_on'],
            'reviewed_at' => $payment['reviewed_at'],
            'created_at' => $payment['created_at'],
        ];
        if ($forStaff) {
            $presented = ['id' => $payment['id']] + $presented + [
                'slip_name' => $payment['slip_name'],
                'reviewed_by' => $payment['reviewed_by'],
                'recorded_by_staff' => $payment['recorded_by_staff'],
            ];
        }

        return $presented;
    }

    /** The status as buyers and staff read it ("Ready to ship, paid" once nothing is left to pay). */
    public static function statusLabel(string $status, array $amounts): string
    {
        if ($status === 'ready_to_ship' && $amounts['total_cents'] - $amounts['paid_cents'] <= 0) {
            return 'Ready to ship, paid';
        }

        return Order::STATUSES[$status] ?? $status;
    }

    /**
     * Days until the production due date (negative when late), for orders waiting for or in production.
     *
     * @param array<string, mixed> $order
     */
    public static function daysLeft(array $order): ?int
    {
        if ($order['production_due_on'] === null || !in_array($order['status'], ['confirmed', 'in_production'], true)) {
            return null;
        }
        $today = Format::local()->setTime(0, 0);
        $due = Format::local((string) $order['production_due_on'])->setTime(0, 0);

        return (int) $today->diff($due)->format('%r%a');
    }

    /**
     * The order's documents: the ones the site makes (proforma invoice, balance invoice, receipts)
     * and the files staff attached (the buyer only sees the public ones).
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $payments
     * @return list<array<string, mixed>>
     */
    private function documentList(array $order, array $payments, bool $forStaff): array
    {
        $documents = [];
        if ($order['status'] !== 'cancelled' || $forStaff) {
            $documents[] = ['kind' => 'proforma', 'title' => 'Proforma invoice', 'number' => $order['order_no'], 'date' => $order['placed_at']];
        }
        if ($order['balance_invoice_no'] !== null) {
            $documents[] = ['kind' => 'invoice', 'title' => 'Invoice for the balance', 'number' => $order['balance_invoice_no'], 'date' => $order['balance_invoiced_at']];
        }
        foreach ($payments as $payment) {
            if ($payment['status'] === 'confirmed' && $payment['receipt_no'] !== null) {
                $documents[] = [
                    'kind' => 'receipt',
                    'title' => 'Receipt, ' . strtolower(OrderPayment::KINDS[$payment['kind']] ?? $payment['kind']),
                    'number' => $payment['receipt_no'],
                    'date' => $payment['reviewed_at'],
                ];
            }
        }
        foreach ($this->documents->forOrder($order['id'], !$forStaff) as $file) {
            $document = [
                'kind' => 'file',
                'id' => $file['id'],
                'type' => $file['type'],
                'title' => $file['title'],
                'type_label' => OrderDocument::TYPES[$file['type']] ?? $file['type'],
                'date' => $file['created_at'],
                'mime' => $file['mime'],
                'size_bytes' => $file['size_bytes'],
            ];
            $documents[] = $forStaff ? $document + ['is_public' => $file['is_public']] : $document;
        }

        return $documents;
    }

    /** Which payment the buyer can report in this status. */
    private static function nextPaymentKind(string $status): ?string
    {
        return match ($status) {
            'awaiting_deposit', 'payment_review' => 'deposit',
            'ready_to_ship' => 'balance',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $payments
     */
    private static function canCancel(array $order, array $payments): bool
    {
        return $order['status'] === 'awaiting_deposit'
            && array_filter($payments, static fn (array $p): bool => $p['status'] !== 'rejected') === [];
    }

    /**
     * @param array<mixed> $ids
     * @return list<array<string, mixed>>
     */
    private function chosenAddons(array $ids): array
    {
        $active = array_column($this->options->active(), null, 'id');
        $chosen = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (!isset($active[$id])) {
                throw new ValidationException(['addons' => 'One of the chosen add-ons is no longer offered. Please review them.']);
            }
            $chosen[] = $active[$id];
        }

        return $chosen;
    }

    /** "PK-261016-482": the date in Pakistan and a random 3-digit code (owner decision 2026-10-16). */
    private function newOrderNo(): string
    {
        $prefix = 'PK-' . Format::local()->format('ymd') . '-';
        for ($i = 0; $i < 50; $i++) {
            $orderNo = $prefix . random_int(100, 999);
            if (!$this->orders->orderNoExists($orderNo)) {
                return $orderNo;
            }
        }
        // 50 misses means a very busy day: add a fourth digit.
        return $prefix . random_int(1000, 9999);
    }
}
