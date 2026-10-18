<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Format;
use App\Core\Logger;
use App\Models\MissingPage;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Review;
use App\Models\Settings;
use Throwable;

/**
 * Runs once a day (bin/daily.php, a cron job; owner decision 2026-10-16):
 * - reminds buyers of unpaid orders after 3 and 7 days, and cancels orders still unpaid after 30 days;
 * - asks buyers for a review a week after delivery (Reviews::requestDue);
 * - deletes files uploaded for a quote request or sample order that was never sent;
 * - emails staff a summary of payments to check, sample orders to price, late orders, orders due in the next 3 days
 *   and reviews waiting for approval.
 * Running it twice on the same day sends nothing twice.
 */
final class DailyJob
{
    public const FIRST_REMINDER_DAYS = 3;

    public const SECOND_REMINDER_DAYS = 7;

    public const CANCEL_DAYS = 30;

    private const DUE_SOON_DAYS = 3;

    public function __construct(
        private readonly Order $orders,
        private readonly OrderPayment $payments,
        private readonly OrderService $service,
        private readonly OrderMailer $mailer,
        private readonly Settings $settings,
        private readonly InquiryAttachments $attachments,
        private readonly Reviews $reviewRequests,
        private readonly Review $reviews,
        private readonly MissingPage $missingPages,
        private readonly Logger $logger,
    ) {
    }

    /** @return array{reminded: int, cancelled: int, summary_sent: bool, uploads_deleted: int, reviews_requested: int} */
    public function run(): array
    {
        // Review requests, a week after delivery (each order once).
        $reviewsRequested = 0;
        try {
            $reviewsRequested = $this->reviewRequests->requestDue();
        } catch (Throwable $e) {
            $this->logger->error('Daily job: review requests not sent', ['error' => $e->getMessage()]);
        }

        $today = Format::local()->setTime(0, 0);
        $reminded = 0;
        $cancelled = [];

        // Files uploaded for a quote request or sample order that was never sent.
        $uploadsDeleted = 0;
        try {
            $uploadsDeleted = $this->attachments->deleteStale();
        } catch (Throwable $e) {
            $this->logger->error('Daily job: stale uploads not deleted', ['error' => $e->getMessage()]);
        }

        foreach ($this->orders->unpaid() as $order) {
            try {
                $done = $this->handleUnpaid($order, $today);
            } catch (Throwable $e) {
                $this->logger->error('Daily job: unpaid order not handled', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
                continue;
            }
            if ($done === 'cancelled') {
                $cancelled[] = ['order_no' => $order['order_no'], 'company' => $order['customer']['company_name'] ?? ''];
            } elseif ($done === 'reminded') {
                $reminded++;
            }
        }

        $summarySent = $this->sendSummary($today->format('Y-m-d'), $cancelled, $reminded);

        // Missing addresses nobody has hit for a long time drop off the admin's list.
        try {
            $this->missingPages->prune(gmdate('Y-m-d H:i:s', strtotime('-' . MissingPage::KEEP_DAYS . ' days')));
        } catch (Throwable $e) {
            $this->logger->error('Daily job: missing pages not pruned', ['error' => $e->getMessage()]);
        }
        // The launch checklist shows whether the cron job runs.
        $this->saveState(['last_run_at' => gmdate(DATE_ATOM)]);

        return [
            'reminded' => $reminded,
            'cancelled' => count($cancelled),
            'summary_sent' => $summarySent,
            'uploads_deleted' => $uploadsDeleted,
            'reviews_requested' => $reviewsRequested,
        ];
    }

    /**
     * One unpaid order: a reminder after 3 and 7 days, cancelled after 30.
     *
     * @param array<string, mixed> $order
     * @return string|null "reminded", "cancelled" or null (nothing due today)
     */
    public function handleUnpaid(array $order, \DateTimeImmutable $today): ?string
    {
        // Counted from when the order became payable (a sample order priced by staff, later than it was placed).
        $placed = Format::local($order['priced_at'] ?? $order['placed_at'])->setTime(0, 0);
        $days = (int) $placed->diff($today)->format('%r%a');
        $cancelOn = $placed->modify('+' . self::CANCEL_DAYS . ' days')->format('Y-m-d');

        if ($days >= self::CANCEL_DAYS) {
            $this->cancel($order);

            return 'cancelled';
        }
        if ($days >= self::SECOND_REMINDER_DAYS && $order['reminders_sent'] < 2) {
            $this->remind($order, 2, $cancelOn);

            return 'reminded';
        }
        if ($days >= self::FIRST_REMINDER_DAYS && $order['reminders_sent'] < 1) {
            $this->remind($order, 1, $cancelOn);

            return 'reminded';
        }

        return null;
    }

    /** @param array<string, mixed> $order */
    private function remind(array $order, int $number, string $cancelOn): void
    {
        // Count the reminder first, so a failing mail server never causes repeated reminders.
        $this->orders->updateFields($order['id'], ['reminders_sent' => $number]);
        $this->orders->addEvent($order['id'], 'reminder', null, null, "Payment reminder {$number} sent", 'system', null, false);
        $this->mailer->paymentReminder($this->service->present($order, false), $number, $cancelOn);
    }

    /** @param array<string, mixed> $order */
    private function cancel(array $order): void
    {
        $reason = sprintf('The %s did not arrive within %d days', $order['kind'] === 'sample' ? 'payment' : 'deposit', self::CANCEL_DAYS);
        $changed = $this->orders->changeStatus($order['id'], 'awaiting_deposit', 'cancelled', 'system', null, $reason, [
            'cancelled_at' => gmdate('Y-m-d H:i:s'),
            'cancel_reason' => $reason,
        ]);
        if ($changed) {
            $fresh = $this->orders->find($order['id']);
            if ($fresh !== null) {
                $this->mailer->autoCancelled($this->service->present($fresh, false));
            }
        }
    }

    /**
     * The staff summary, at most once a day.
     *
     * @param list<array{order_no: string, company: string}> $cancelled
     */
    public function sendSummary(string $today, array $cancelled, int $reminded): bool
    {
        $state = $this->settings->get('daily_job', []);
        if (is_array($state) && ($state['summary_sent_on'] ?? null) === $today) {
            return false;
        }

        $payments = [];
        foreach ($this->payments->queue('submitted', 1, 50)['items'] as $payment) {
            $order = $this->orders->find($payment['order_id']);
            $payments[] = [
                'order_no' => $order['order_no'] ?? '',
                'company' => $order['customer']['company_name'] ?? '',
                'kind' => ($order['kind'] ?? 'bulk') === 'sample' ? 'sample payment' : strtolower(OrderPayment::KINDS[$payment['kind']] ?? $payment['kind']),
                'amount_cents' => $payment['amount_cents'],
            ];
        }
        $toPrice = array_map(static fn (array $order): array => [
            'order_no' => $order['order_no'],
            'company' => $order['customer']['company_name'] ?? '',
            'placed' => Format::date(Format::local($order['placed_at'])->format('Y-m-d')),
        ], $this->orders->awaitingPrice());
        $soon = Format::local($today)->modify('+' . self::DUE_SOON_DAYS . ' days')->format('Y-m-d');
        $late = [];
        $dueSoon = [];
        foreach ($this->orders->dueBy($soon) as $order) {
            $row = [
                'order_no' => $order['order_no'],
                'company' => $order['customer']['company_name'] ?? '',
                'due' => (string) $order['production_due_on'],
                'days' => (int) OrderService::daysLeft($order),
            ];
            if ($order['production_due_on'] < $today) {
                $late[] = $row;
            } else {
                $dueSoon[] = $row;
            }
        }

        $reviews = $this->reviews->counts()['pending'];

        if ($payments === [] && $toPrice === [] && $late === [] && $dueSoon === [] && $cancelled === [] && $reminded === 0 && $reviews === 0) {
            return false;
        }
        $this->mailer->staffSummary([
            'date' => Format::date($today),
            'payments' => $payments,
            'toPrice' => $toPrice,
            'reviews' => $reviews,
            'late' => $late,
            'dueSoon' => $dueSoon,
            'cancelled' => $cancelled,
            'reminded' => $reminded,
        ]);
        $this->saveState(['summary_sent_on' => $today]);

        return true;
    }

    /** @param array<string, string> $changes merged into the daily_job setting */
    private function saveState(array $changes): void
    {
        $state = $this->settings->get('daily_job', []);
        $this->settings->set('daily_job', $changes + (is_array($state) ? $state : []), false);
    }
}
