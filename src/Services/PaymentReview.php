<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Format;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Models\Order;
use App\Models\OrderPayment;
use PDOException;
use Throwable;

/**
 * Admins check reported transfers against the bank statement (owner decision: admins only).
 *
 * - Confirming gives the payment a receipt number (RCPT-2026-00001), adds what actually arrived to the
 *   order, emails the buyer a PDF receipt, and starts production once the deposit is covered (or when
 *   the admin says so, e.g. when bank fees made it a little short).
 * - Rejecting frees the transaction ID and tells the buyer why.
 */
final class PaymentReview
{
    public function __construct(
        private readonly Database $db,
        private readonly Order $orders,
        private readonly OrderPayment $payments,
        private readonly OrderService $service,
        private readonly DocumentNumbers $numbers,
        private readonly OrderPdfs $pdfs,
        private readonly OrderMailer $mailer,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{id: int} $staff
     * @return array<string, mixed> the order (staff view)
     */
    public function confirm(int $paymentId, array $staff, int $receivedCents, string $receivedOn, bool $startAnyway): array
    {
        $payment = $this->payment($paymentId);
        $orderId = $payment['order_id'];
        $productionStarted = false;

        $this->db->transaction(function () use ($payment, $paymentId, $orderId, $staff, $receivedCents, $receivedOn, $startAnyway, &$productionStarted): void {
            $receiptNo = $this->numbers->next(DocumentNumbers::RECEIPT);
            if (!$this->payments->confirm($paymentId, $staff['id'], $receivedCents, $receivedOn, $receiptNo)) {
                throw new HttpException(409, 'already_reviewed', 'This payment was already reviewed. Reload the page.');
            }
            $this->orders->addPaid($orderId, $receivedCents);
            $this->orders->addEvent(
                $orderId,
                'payment_confirmed',
                null,
                null,
                sprintf('%s of %s received (receipt %s)', OrderPayment::KINDS[$payment['kind']] ?? 'Payment', Format::money($receivedCents), $receiptNo),
                'staff',
                $staff['id'],
            );

            $order = (array) $this->orders->find($orderId);
            if ($payment['kind'] !== 'deposit' || !in_array($order['status'], ['awaiting_deposit', 'payment_review'], true)) {
                return;
            }
            if ($order['paid_cents'] >= $order['deposit_cents'] || $startAnyway) {
                $due = Format::local()->modify('+' . $order['lead_time_days'] . ' days')->format('Y-m-d');
                $this->orders->changeStatus(
                    $orderId,
                    $order['status'],
                    'confirmed',
                    'staff',
                    $staff['id'],
                    $order['paid_cents'] < $order['deposit_cents'] ? 'Production started before the full deposit arrived' : null,
                    ['confirmed_at' => gmdate('Y-m-d H:i:s'), 'production_due_on' => $due],
                );
                $productionStarted = true;
            } elseif ($order['status'] === 'payment_review' && $this->payments->countPending($orderId) === 0) {
                $this->orders->changeStatus($orderId, 'payment_review', 'awaiting_deposit', 'staff', $staff['id'], 'Part of the deposit received');
            }
        });

        $order = (array) $this->orders->find($orderId);
        $confirmed = OrderService::presentPayment((array) $this->payments->find($paymentId), true);
        try {
            $buyerView = $this->service->present($order, false);
            $this->mailer->paymentConfirmed($buyerView, $confirmed, $this->pdfs->receipt($buyerView, $confirmed), $productionStarted);
            $this->payments->markReceiptSent($paymentId);
        } catch (Throwable $e) {
            $this->logger->error('Receipt email could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
        }

        return $this->service->present($order, true);
    }

    /**
     * @param array{id: int} $staff
     * @return array<string, mixed> the order (staff view)
     */
    public function reject(int $paymentId, array $staff, string $reason): array
    {
        $payment = $this->payment($paymentId);
        $orderId = $payment['order_id'];

        $this->db->transaction(function () use ($payment, $paymentId, $orderId, $staff, $reason): void {
            if (!$this->payments->reject($paymentId, $staff['id'], $reason)) {
                throw new HttpException(409, 'already_reviewed', 'This payment was already reviewed. Reload the page.');
            }
            $this->orders->addEvent(
                $orderId,
                'payment_rejected',
                null,
                null,
                sprintf('%s of %s not confirmed: %s', OrderPayment::KINDS[$payment['kind']] ?? 'Payment', Format::money($payment['amount_cents']), $reason),
                'staff',
                $staff['id'],
            );
            $order = (array) $this->orders->find($orderId);
            if ($order['status'] === 'payment_review' && $this->payments->countPending($orderId) === 0) {
                $this->orders->changeStatus($orderId, 'payment_review', 'awaiting_deposit', 'staff', $staff['id']);
            }
        });

        $order = (array) $this->orders->find($orderId);
        try {
            $this->mailer->paymentRejected($this->service->present($order, false), OrderService::presentPayment((array) $this->payments->find($paymentId), true));
        } catch (Throwable $e) {
            $this->logger->error('Rejection email could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
        }

        return $this->service->present($order, true);
    }

    /**
     * Records a transfer the buyer did not report (e.g. they told us by email) and confirms it at once.
     *
     * @param array<string, mixed> $order
     * @param array{id: int} $staff
     * @param array<string, mixed> $data validated input
     * @return array<string, mixed> the order (staff view)
     */
    public function record(array $order, array $staff, array $data): array
    {
        $amounts = OrderService::amounts($order, $this->orders->addons($order['id']));
        if (in_array($order['status'], ['cancelled', 'delivered'], true) || $amounts['total_cents'] - $amounts['paid_cents'] <= 0) {
            throw new HttpException(409, 'payment_not_due', 'Nothing is left to pay on this order.');
        }
        if ($this->payments->transactionUsed((string) $data['transaction_id'])) {
            throw new ValidationException(['transaction_id' => 'This transaction ID has already been recorded.']);
        }

        try {
            $paymentId = $this->payments->create($order['id'], [
                'kind' => $order['paid_cents'] < $order['deposit_cents'] ? 'deposit' : 'balance',
                'amount_cents' => $data['amount_cents'],
                'currency' => $order['currency'],
                'transaction_id' => $data['transaction_id'],
                'transferred_on' => $data['transferred_on'],
                'payer_name' => $data['payer_name'],
                'payer_bank' => $data['payer_bank'],
                'notes' => $data['notes'] ?? null,
                'recorded_by_staff' => 1,
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'order_payments_transaction')) {
                throw new ValidationException(['transaction_id' => 'This transaction ID has already been recorded.']);
            }
            throw $e;
        }

        return $this->confirm($paymentId, $staff, (int) $data['amount_cents'], (string) ($data['received_on'] ?? $data['transferred_on']), (bool) ($data['start_anyway'] ?? false));
    }

    /** @return array<string, mixed> */
    private function payment(int $id): array
    {
        $payment = $this->payments->find($id);
        if ($payment === null) {
            throw HttpException::notFound('This payment does not exist.');
        }
        if ($payment['status'] !== 'submitted') {
            throw new HttpException(409, 'already_reviewed', 'This payment was already reviewed. Reload the page.');
        }

        return $payment;
    }
}
