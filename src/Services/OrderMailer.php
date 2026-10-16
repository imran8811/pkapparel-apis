<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Format;
use App\Models\Settings;

/** Order emails to the buyer and to staff (the same address as inquiry notifications). */
final class OrderMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Settings $settings,
        private readonly ProformaInvoice $invoices,
    ) {
    }

    /**
     * Order placed: the buyer gets the PI (attached), the bank details and the next steps; staff get a summary.
     *
     * @param array<string, mixed> $order buyer's view of the order
     * @param array<string, mixed> $staffView staff view (with ids)
     */
    public function placed(array $order, array $staffView): void
    {
        $pdf = $this->invoices->render($order, $this->bankOrNull($order));
        $data = $this->layoutData() + ['order' => $order, 'links' => $this->links($order['order_no'], $staffView['id'])];

        $this->mailer->send(
            (string) $order['customer']['email'],
            "Your order {$order['order_no']} and proforma invoice | PK Apparel",
            $this->mailer->render('order-placed-customer', $data + ['preheader' => sprintf('Deposit due: %s. Bank details and your proforma invoice are inside.', Format::money($order['amounts']['due_now_cents']))]),
            $this->config->string('mail.admin_to'),
            [['name' => ProformaInvoice::filename($order['order_no']), 'content' => $pdf, 'type' => 'application/pdf']],
        );

        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] New order from %s, %s', $order['order_no'], $order['customer']['company_name'], Format::money($order['amounts']['total_cents'])),
            $this->mailer->render('order-placed-admin', $data + ['preheader' => sprintf('%d pcs, %s', $order['total_pcs'], $order['shipping_mode_label'])]),
            (string) $order['customer']['email'],
        );
    }

    /**
     * @param array<string, mixed> $order buyer's view
     * @param array<string, mixed> $staffView
     * @param array<string, mixed> $payment
     */
    public function paymentSubmitted(array $order, array $staffView, array $payment): void
    {
        $data = $this->layoutData() + ['order' => $order, 'payment' => $payment, 'links' => $this->links($order['order_no'], $staffView['id'])];

        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] Payment to check: %s, transaction %s', $order['order_no'], Format::money($payment['amount_cents']), $payment['transaction_id']),
            $this->mailer->render('payment-submitted-admin', $data + ['preheader' => sprintf('%s reported a transfer of %s.', $order['customer']['company_name'], Format::money($payment['amount_cents']))]),
            (string) $order['customer']['email'],
        );

        $this->mailer->send(
            (string) $order['customer']['email'],
            "We received your payment details for {$order['order_no']} | PK Apparel",
            $this->mailer->render('payment-submitted-customer', $data + ['preheader' => 'We are checking your transfer and will email your receipt.']),
            $this->config->string('mail.admin_to'),
        );
    }

    /** @param array<string, mixed> $staffView */
    public function cancelled(array $staffView): void
    {
        $data = $this->layoutData() + ['order' => $staffView, 'links' => $this->links($staffView['order_no'], $staffView['id'])];

        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] Order cancelled by %s', $staffView['order_no'], $staffView['customer']['company_name']),
            $this->mailer->render('order-cancelled-admin', $data + ['preheader' => 'The buyer cancelled the order before paying.']),
            (string) $staffView['customer']['email'],
        );
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function bankOrNull(array $order): ?array
    {
        return is_array($order['bank'] ?? null) ? $order['bank'] : null;
    }

    /** @return array<string, string> */
    private function links(string $orderNo, int $orderId): array
    {
        $web = rtrim($this->config->string('web.url'), '/');

        return [
            'order' => "{$web}/account/orders/{$orderNo}",
            'confirm_payment' => "{$web}/account/orders/{$orderNo}/confirm-payment",
            'admin' => "{$web}/admin/orders/{$orderId}",
        ];
    }

    /** @return array<string, mixed> */
    private function layoutData(): array
    {
        return ['contact' => $this->settings->get('contact', []), 'addresses' => $this->settings->get('addresses', [])];
    }
}
