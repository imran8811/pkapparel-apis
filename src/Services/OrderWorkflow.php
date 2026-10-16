<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Models\Order;
use App\Models\Shipment;
use Throwable;

/**
 * What staff do with an order after it is placed (payments are in PaymentReview, production in Production):
 * hold and resume, cancel, change the due date, set the final freight and "on request" prices,
 * ready to ship (issues the balance invoice), shipped, delivered, and internal notes.
 * Each step is recorded in the order's history and, where it concerns the buyer, emailed to them.
 */
final class OrderWorkflow
{
    /** Statuses an order can be put on hold or cancelled from. */
    private const ACTIVE = ['awaiting_deposit', 'payment_review', 'confirmed', 'in_production', 'ready_to_ship'];

    public function __construct(
        private readonly Database $db,
        private readonly Order $orders,
        private readonly Shipment $shipments,
        private readonly OrderService $service,
        private readonly DocumentNumbers $numbers,
        private readonly OrderPdfs $pdfs,
        private readonly OrderMailer $mailer,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function hold(array $order, array $staff, string $reason): void
    {
        $this->expect($order, self::ACTIVE, 'Only an open order can be put on hold.');
        $this->change($order, 'on_hold', $staff, $reason, ['status_before_hold' => $order['status']]);
        $this->notify($order['id'], fn (array $view) => $this->mailer->notice($view, 'Your order is on hold', 'We have put your order on hold for now. We will let you know when it continues.', $reason));
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function resume(array $order, array $staff): void
    {
        $this->expect($order, ['on_hold'], 'This order is not on hold.');
        $back = $order['status_before_hold'] ?: 'awaiting_deposit';
        $this->change($order, $back, $staff, null, ['status_before_hold' => null]);
        $this->notify($order['id'], fn (array $view) => $this->mailer->notice($view, 'Your order continues', 'Your order is no longer on hold. Thank you for your patience.'));
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function cancel(array $order, array $staff, string $reason, bool $notify): void
    {
        $this->expect($order, [...self::ACTIVE, 'on_hold'], 'This order can no longer be cancelled.');
        $this->change($order, 'cancelled', $staff, $reason, ['cancelled_at' => gmdate('Y-m-d H:i:s'), 'cancel_reason' => $reason, 'status_before_hold' => null]);
        if ($notify) {
            $paid = $order['paid_cents'] > 0 ? ' We will contact you about the payment you made.' : '';
            $this->notify($order['id'], fn (array $view) => $this->mailer->notice($view, 'Your order was cancelled', 'We have cancelled your order.' . $paid, $reason));
        }
    }

    /**
     * A new production due date (the "extend lead time per order" of §14).
     *
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function changeDueDate(array $order, array $staff, string $date, string $reason, bool $notify): void
    {
        $this->expect($order, ['confirmed', 'in_production', 'on_hold'], 'The due date can only change while the order is being made.');
        if ($order['production_due_on'] === null) {
            throw new HttpException(409, 'no_due_date', 'This order has no due date yet: it starts when the deposit is confirmed.');
        }
        $this->db->transaction(function () use ($order, $staff, $date, $reason): void {
            $this->orders->updateFields($order['id'], ['production_due_on' => $date]);
            $this->orders->addEvent($order['id'], 'due_date', null, null, sprintf('Ready date moved from %s to %s: %s', Format::date($order['production_due_on']), Format::date($date), $reason), 'staff', $staff['id']);
        });
        if ($notify) {
            $this->notify($order['id'], fn (array $view) => $this->mailer->notice($view, 'New ready date for your order', sprintf('Your order is now due to be ready on %s.', Format::date($date)), $reason));
        }
    }

    /**
     * The final freight and prices for "on request" add-ons. They change the balance only (the deposit is fixed).
     *
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     * @param array<int, int> $addonPrices add-on id => price per unit in cents
     */
    public function setCharges(array $order, array $staff, ?int $freightCents, bool $clearFreight, array $addonPrices): void
    {
        if (in_array($order['status'], ['shipped', 'delivered', 'cancelled'], true)) {
            throw new HttpException(409, 'locked', 'The charges of a shipped or cancelled order cannot change.');
        }
        $addons = array_column($this->orders->addons($order['id']), null, 'id');
        foreach (array_keys($addonPrices) as $addonId) {
            if (!isset($addons[$addonId])) {
                throw new ValidationException(['addons' => 'One of the add-ons does not belong to this order.']);
            }
        }

        $this->db->transaction(function () use ($order, $staff, $freightCents, $clearFreight, $addonPrices, $addons): void {
            $notes = [];
            if ($clearFreight || $freightCents !== null) {
                if ($order['shipping_mode'] === 'forwarder') {
                    throw new ValidationException(['freight' => 'The buyer books the freight for this order.']);
                }
                $this->orders->updateFields($order['id'], ['freight_final_cents' => $clearFreight ? null : $freightCents]);
                $notes[] = $clearFreight ? 'Final freight removed' : 'Final freight ' . Format::money((int) $freightCents);
            }
            foreach ($addonPrices as $addonId => $cents) {
                $this->orders->priceAddon($order['id'], $addonId, $cents);
                $notes[] = sprintf('%s priced at %s each', $addons[$addonId]['name'], Format::money($cents));
            }
            if ($notes !== []) {
                $this->orders->addEvent($order['id'], 'charges', null, null, implode('. ', $notes), 'staff', $staff['id']);
            }
        });
    }

    /**
     * Production is done: issue the balance invoice (INV-2026-00001) and email it to the buyer.
     *
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function readyToShip(array $order, array $staff): void
    {
        $this->expect($order, ['confirmed', 'in_production'], 'Only an order in production can be marked ready to ship.');
        if ($order['shipping_mode'] !== 'forwarder' && $order['freight_final_cents'] === null && $order['freight_estimate_cents'] === null) {
            throw new HttpException(409, 'freight_missing', 'Enter the final freight first, so the balance invoice is complete.');
        }
        if (array_filter($this->orders->addons($order['id']), static fn (array $a): bool => $a['line_cents'] === null) !== []) {
            throw new HttpException(409, 'addons_unpriced', 'Price the add-ons marked "on request" first, so the balance invoice is complete.');
        }

        $this->db->transaction(function () use ($order, $staff): void {
            $extra = ['balance_invoice_no' => $order['balance_invoice_no'] ?? $this->numbers->next(DocumentNumbers::INVOICE), 'balance_invoiced_at' => gmdate('Y-m-d H:i:s')];
            if ($order['shipping_mode'] !== 'forwarder' && $order['freight_final_cents'] === null) {
                // The estimate becomes the final freight unless staff entered another figure.
                $extra['freight_final_cents'] = $order['freight_estimate_cents'];
            }
            $this->change($order, 'ready_to_ship', $staff, null, $extra);
        });

        $this->notify($order['id'], function (array $view): void {
            $this->mailer->readyToShip($view, $this->pdfs->balanceInvoice($view, $this->service->bank()));
        });
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     * @param array<string, mixed> $data validated shipment details
     */
    public function ship(array $order, array $staff, array $data, ?string $unpaidReason): void
    {
        $this->expect($order, ['ready_to_ship'], 'Mark the order ready to ship first.');
        $amounts = OrderService::amounts($order, $this->orders->addons($order['id']));
        $unpaid = $amounts['total_cents'] - $amounts['paid_cents'];
        if ($unpaid > 0 && ($unpaidReason === null || $unpaidReason === '')) {
            throw new HttpException(409, 'not_paid', sprintf('%s of this order is not paid yet. Give a reason to ship it anyway.', Format::money($unpaid)));
        }

        $this->db->transaction(function () use ($order, $staff, $data, $unpaid, $unpaidReason): void {
            $this->shipments->save($order['id'], $data, $staff['id']);
            $this->change($order, 'shipped', $staff, null, ['shipped_at' => gmdate('Y-m-d H:i:s')]);
            if ($unpaid > 0) {
                $this->orders->addEvent($order['id'], 'note', null, null, sprintf('Shipped with %s still unpaid: %s', Format::money($unpaid), $unpaidReason), 'staff', $staff['id'], false);
            }
        });
        $this->notify($order['id'], fn (array $view) => $this->mailer->shipped($view));
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function deliver(array $order, array $staff): void
    {
        $this->expect($order, ['shipped'], 'Only a shipped order can be marked delivered.');
        $this->change($order, 'delivered', $staff, null, ['delivered_at' => gmdate('Y-m-d H:i:s')]);
        $this->notify($order['id'], fn (array $view) => $this->mailer->delivered($view));
    }

    /**
     * A note for the team (never shown to the buyer).
     *
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     */
    public function addNote(array $order, array $staff, string $note): void
    {
        $this->orders->addEvent($order['id'], 'note', null, null, $note, 'staff', $staff['id'], false);
    }

    /**
     * @param array<string, mixed> $order
     * @param list<string> $allowed
     */
    private function expect(array $order, array $allowed, string $message): void
    {
        if (!in_array($order['status'], $allowed, true)) {
            throw new HttpException(409, 'wrong_status', $message);
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     * @param array<string, mixed> $extra
     */
    private function change(array $order, string $to, array $staff, ?string $note, array $extra = []): void
    {
        $changed = $this->orders->changeStatus($order['id'], $order['status'], $to, 'staff', $staff['id'], $note, $extra);
        if (!$changed) {
            throw new HttpException(409, 'changed_meanwhile', 'The order changed in the meantime. Reload the page.');
        }
    }

    /** Emails the buyer after a change (failures are logged, never shown to staff as errors). */
    private function notify(int $orderId, callable $send): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return;
        }
        try {
            $send($this->service->present($order, false));
        } catch (Throwable $e) {
            $this->logger->error('Order email could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
        }
    }
}
