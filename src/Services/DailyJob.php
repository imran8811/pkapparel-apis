<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Format;
use App\Core\Logger;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Settings;
use Throwable;

/**
 * Runs once a day (bin/daily.php, a cron job; owner decision 2026-10-16):
 * - reminds buyers of unpaid orders after 3 and 7 days, and cancels orders still unpaid after 30 days;
 * - emails staff a summary of payments to check, late orders and orders due in the next 3 days.
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
        private readonly Logger $logger,
    ) {
    }

    /** @return array{reminded: int, cancelled: int, summary_sent: bool} */
    public function run(): array
    {
        $today = Format::local()->setTime(0, 0);
        $reminded = 0;
        $cancelled = [];

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

        return ['reminded' => $reminded, 'cancelled' => count($cancelled), 'summary_sent' => $summarySent];
    }

    /**
     * One unpaid order: a reminder after 3 and 7 days, cancelled after 30.
     *
     * @param array<string, mixed> $order
     * @return string|null "reminded", "cancelled" or null (nothing due today)
     */
    public function handleUnpaid(array $order, \DateTimeImmutable $today): ?string
    {
        $placed = Format::local($order['placed_at'])->setTime(0, 0);
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
        $reason = 'The deposit did not arrive within ' . self::CANCEL_DAYS . ' days';
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
                'kind' => strtolower(OrderPayment::KINDS[$payment['kind']] ?? $payment['kind']),
                'amount_cents' => $payment['amount_cents'],
            ];
        }
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

        if ($payments === [] && $late === [] && $dueSoon === [] && $cancelled === [] && $reminded === 0) {
            return false;
        }
        $this->mailer->staffSummary([
            'date' => Format::date($today),
            'payments' => $payments,
            'late' => $late,
            'dueSoon' => $dueSoon,
            'cancelled' => $cancelled,
            'reminded' => $reminded,
        ]);
        $this->settings->set('daily_job', ['summary_sent_on' => $today], false);

        return true;
    }
}
