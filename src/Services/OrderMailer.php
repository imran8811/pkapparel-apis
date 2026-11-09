<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Format;
use App\Models\Settings;

/**
 * Order emails to the buyer and to staff (the same address as inquiry notifications).
 * Stock orders get their own wording, amounts in rupees, the local payment details and links to the stock pages;
 * their emails never name the country (the layout's stock variant).
 */
final class OrderMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Settings $settings,
        private readonly OrderPdfs $pdfs,
        private readonly StockSettings $stock,
    ) {
    }

    /**
     * Stock order placed: the buyer gets the amount, the payment details and the next steps; staff get a summary.
     *
     * @param array<string, mixed> $order buyer's view of the order
     * @param array<string, mixed> $staffView staff view (with ids)
     */
    public function stockPlaced(array $order, array $staffView): void
    {
        $links = $this->links($order['order_no'], $staffView['id'], 'stock');
        $total = self::money($order['amounts']['total_cents'], $order);
        $due = self::money($order['amounts']['due_now_cents'], $order);
        $cargo = $order['shipping_mode'] === 'cargo';

        $this->toBuyer($order, "Your order {$order['order_no']}", [
            'title' => 'Thank you for your order',
            'intro' => sprintf('Please pay %s to confirm your order. Your sets are held for you once the payment is confirmed.', $due),
            'facts' => [
                'Order' => $order['order_no'],
                'Jeans' => self::setsText($order),
                'Total' => $total,
                'Delivery' => $cargo ? sprintf('Cargo to %s', $order['address']['city'] ?? 'your city') : 'You collect it from us',
                'Payment reference' => $order['order_no'],
            ],
            'paragraphs' => [
                'Pay by bank transfer, JazzCash or Easypaisa, then tell us the transaction ID on the Confirm payment page.',
                $cargo
                    ? sprintf('Once the payment is confirmed, we book the cargo and send your order within %d days. You pay the cargo charges when it arrives.', $order['lead_time_days'])
                    : sprintf('Once the payment is confirmed, your order is ready to collect within %d days.', $order['lead_time_days']),
                sprintf('An order that is not paid within %d days is cancelled.', $this->stock->paymentDays()),
            ],
            'stockPayment' => $this->bankOrNull($order) ?? [],
            'button' => ['label' => 'Confirm payment', 'url' => $links['confirm_payment']],
        ]);

        $this->toStaff($staffView, sprintf('[%s] New stock order from %s, %s', $order['order_no'], $order['customer']['company_name'], $total), [
            'title' => 'New stock order',
            'intro' => sprintf('%s placed a stock order. It waits for the payment; the sets are taken out of stock when you confirm it.', $order['customer']['company_name']),
            'facts' => [
                'Buyer' => trim($order['customer']['contact_name'] . ', ' . ($order['customer']['city'] ?? ''), ', '),
                'Phone' => $order['customer']['phone'],
                'Jeans' => self::setsText($order),
                'Total' => $total,
                'Delivery' => $cargo ? sprintf('Cargo to %s', $order['address']['city'] ?? '') : 'Collects it',
                'Cargo company wanted' => $order['forwarder']['name'] ?? null,
                'Notes' => $order['notes'],
            ],
            'lines' => true,
        ], $links);
    }

    /** "12 sets (72 pcs)" */
    private static function setsText(array $order): string
    {
        $sets = array_sum(array_map(static fn (array $line): int => (int) ($line['sets'] ?? 0), $order['lines'] ?? []));

        return sprintf('%d %s (%d pcs)', $sets, $sets === 1 ? 'set' : 'sets', $order['total_pcs']);
    }

    /** An amount in the order's currency. */
    private static function money(int $cents, array $order): string
    {
        return Format::money($cents, (string) ($order['currency'] ?? 'USD'));
    }

    /** @param array<string, mixed> $order */
    private static function isStock(array $order): bool
    {
        return ($order['kind'] ?? 'bulk') === 'stock';
    }

    /**
     * Order placed: the buyer gets the PI (attached), the bank details and the next steps; staff get a summary.
     *
     * @param array<string, mixed> $order buyer's view of the order
     * @param array<string, mixed> $staffView staff view (with ids)
     */
    public function placed(array $order, array $staffView): void
    {
        $pdf = $this->pdfs->proforma($order, $this->bankOrNull($order));
        $data = $this->layoutData($order) + ['order' => $order, 'links' => $this->links($order['order_no'], $staffView['id'], $order['kind'])];

        $this->mailer->send(
            (string) $order['customer']['email'],
            "Your order {$order['order_no']} and proforma invoice | PK Apparel",
            $this->mailer->render('order-placed-customer', $data + ['preheader' => sprintf('Deposit due: %s. Bank details and your proforma invoice are inside.', Format::money($order['amounts']['due_now_cents']))]),
            $this->config->string('mail.admin_to'),
            [['name' => OrderPdfs::proformaFilename($order['order_no'], $order['kind']), 'content' => $pdf, 'type' => 'application/pdf']],
        );

        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] New order from %s, %s', $order['order_no'], $order['customer']['company_name'], Format::money($order['amounts']['total_cents'])),
            $this->mailer->render('order-placed-admin', $data + ['preheader' => sprintf('%d pcs, %s', $order['total_pcs'], $order['shipping_mode_label'])]),
            (string) $order['customer']['email'],
        );
    }

    /**
     * Sample order placed. Priced: the buyer gets the sample invoice (attached) and the bank details.
     * Waiting for our price: the buyer is told that the invoice follows. Staff get a summary either way.
     *
     * @param array<string, mixed> $order buyer's view of the order
     * @param array<string, mixed> $staffView staff view (with ids)
     */
    public function samplePlaced(array $order, array $staffView): void
    {
        $links = $this->links($order['order_no'], $staffView['id'], $order['kind']);
        if ($order['status'] === 'awaiting_price') {
            $this->toBuyer($order, "We received your sample order {$order['order_no']}", [
                'title' => 'Thank you for your sample order',
                'intro' => sprintf('We are confirming the price of %s and will email you the sample invoice within 1 to 2 working days.', self::toPrice($order)),
                'facts' => ['Order' => $order['order_no'], 'Samples' => sprintf('%d pcs', $order['total_pcs']), 'Deliver to' => self::destination($order)],
                'button' => ['label' => 'View your order', 'url' => $links['order']],
            ]);
        } else {
            $this->sampleInvoice($order, "Your sample order {$order['order_no']} and invoice", 'Thank you for your sample order');
        }

        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] New sample order from %s%s', $order['order_no'], $order['customer']['company_name'], $order['status'] === 'awaiting_price' ? ', needs a price' : ', ' . Format::money($order['amounts']['total_cents'])),
            $this->mailer->render('order-placed-admin', $this->layoutData() + ['order' => $order, 'links' => $links, 'preheader' => sprintf('%d sample pcs to %s', $order['total_pcs'], self::destination($order))]),
            (string) $order['customer']['email'],
        );
    }

    /**
     * Staff priced a sample order: the buyer gets the sample invoice and the bank details.
     *
     * @param array<string, mixed> $order buyer's view
     */
    public function samplePriced(array $order): void
    {
        $this->sampleInvoice($order, "Your sample invoice for order {$order['order_no']}", 'Your sample order is ready to pay');
    }

    /**
     * @param array<string, mixed> $order buyer's view
     * @param array<string, mixed> $staffView
     * @param array<string, mixed> $payment
     */
    public function paymentSubmitted(array $order, array $staffView, array $payment): void
    {
        $data = $this->layoutData($order) + ['order' => $order, 'payment' => $payment, 'links' => $this->links($order['order_no'], $staffView['id'], $order['kind'])];
        $amount = self::money($payment['amount_cents'], $order);

        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] Payment to check: %s, transaction %s', $order['order_no'], $amount, $payment['transaction_id']),
            $this->mailer->render('payment-submitted-admin', $data + ['preheader' => sprintf('%s reported a transfer of %s.', $order['customer']['company_name'], $amount)]),
            (string) $order['customer']['email'],
        );

        if (self::isStock($order)) {
            $this->toBuyer($order, "We received your payment details for {$order['order_no']}", [
                'title' => 'Thank you, we are checking your payment',
                'intro' => sprintf('You reported a payment of %s (transaction %s, sent on %s).', $amount, $payment['transaction_id'], Format::date($payment['transferred_on'])),
                'paragraphs' => ['We match it with our account and email you a receipt once it is confirmed. Your sets are then taken out of stock for you.'],
                'button' => ['label' => 'View your order', 'url' => $data['links']['order']],
            ]);

            return;
        }

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
        $data = $this->layoutData($staffView) + ['order' => $staffView, 'links' => $this->links($staffView['order_no'], $staffView['id'], $staffView['kind'])];

        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] Order cancelled by %s', $staffView['order_no'], $staffView['customer']['company_name']),
            $this->mailer->render('order-cancelled-admin', $data + ['preheader' => 'The buyer cancelled the order before paying.']),
            (string) $staffView['customer']['email'],
        );
    }

    /**
     * A payment was confirmed: the receipt (PDF) goes to the buyer, with the production due date when production starts.
     *
     * @param array<string, mixed> $order buyer's view after the confirmation
     * @param array<string, mixed> $payment the confirmed payment (staff view, with the receipt number)
     */
    public function paymentConfirmed(array $order, array $payment, string $receiptPdf, bool $productionStarted): void
    {
        $outstanding = max(0, $order['amounts']['total_cents'] - $order['amounts']['paid_cents']);
        if (self::isStock($order)) {
            $this->stockPaymentConfirmed($order, $payment, $receiptPdf, $productionStarted, $outstanding);

            return;
        }
        $paragraphs = [];
        if ($productionStarted && self::isSample($order)) {
            $paragraphs[] = sprintf(
                'We are preparing your samples. They are due to be ready on %s, and we will email you the tracking as soon as they ship.',
                Format::date($order['production_due_on']),
            );
        } elseif ($productionStarted) {
            $paragraphs[] = sprintf(
                'Production of your order has started. It is due to be ready on %s. We will keep you posted on each step, with photos, on your order page.',
                Format::date($order['production_due_on']),
            );
        } elseif ($outstanding > 0 && $payment['kind'] === 'deposit' && $order['status'] !== 'confirmed') {
            $paragraphs[] = sprintf('This does not cover the full %s yet. Please send the rest, or contact us if you think this is a mistake.', self::isSample($order) ? 'amount' : 'deposit');
        }
        if ($payment['kind'] === 'balance' && $outstanding === 0) {
            $paragraphs[] = 'Your order is now paid in full. We will let you know as soon as it ships.';
        }
        if ($productionStarted && ($order['credits'] ?? []) !== []) {
            $paragraphs[] = sprintf(
                'The cost of your samples (%s) is deducted from the balance: %s.',
                implode(', ', array_column($order['credits'], 'order_no')),
                Format::money(array_sum(array_column($order['credits'], 'cents'))),
            );
        }

        $this->toBuyer($order, sprintf('Payment received for %s (receipt %s)', $order['order_no'], $payment['receipt_no']), [
            'title' => 'Thank you, we received your payment',
            'intro' => sprintf('Your %s of %s has reached our bank account. Your receipt is attached.', strtolower($payment['kind_label']), Format::money((int) $payment['received_cents'])),
            'facts' => [
                'Receipt' => $payment['receipt_no'],
                'Amount received' => Format::money((int) $payment['received_cents']),
                'Paid so far' => Format::money($order['amounts']['paid_cents']),
                'Still to pay' => Format::money($outstanding),
            ],
            'paragraphs' => $paragraphs,
            'button' => ['label' => 'View your order', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['order']],
        ], [['name' => OrderPdfs::receiptFilename($payment['receipt_no']), 'content' => $receiptPdf, 'type' => 'application/pdf']]);
    }

    /**
     * A stock order's payment was confirmed: its sets are taken out of stock for the buyer; the receipt is attached.
     *
     * @param array<string, mixed> $order buyer's view after the confirmation
     * @param array<string, mixed> $payment the confirmed payment
     */
    private function stockPaymentConfirmed(array $order, array $payment, string $receiptPdf, bool $confirmed, int $outstanding): void
    {
        $paragraphs = [];
        if ($confirmed && $order['shipping_mode'] === 'cargo') {
            $paragraphs[] = sprintf('Your sets are taken out of stock for you. We are packing your order and will send it by cargo by %s, then email you the cargo company and the bilty number.', Format::date($order['production_due_on']));
        } elseif ($confirmed) {
            $pickup = $this->stock->pickup();
            $paragraphs[] = sprintf('Your sets are taken out of stock for you. Your order will be ready to collect on %s%s.', Format::date($order['production_due_on']), $pickup['address'] !== null ? ' at ' . $pickup['address'] : '');
            if ($pickup['hours'] !== null) {
                $paragraphs[] = 'Collection times: ' . $pickup['hours'] . '.';
            }
        } elseif ($outstanding > 0) {
            $paragraphs[] = 'This does not cover the full amount yet. Please send the rest, or contact us if you think this is a mistake.';
        }

        $this->toBuyer($order, sprintf('Payment received for %s (receipt %s)', $order['order_no'], $payment['receipt_no']), [
            'title' => 'Thank you, we received your payment',
            'intro' => sprintf('Your payment of %s is confirmed. Your receipt is attached.', self::money((int) $payment['received_cents'], $order)),
            'facts' => [
                'Receipt' => $payment['receipt_no'],
                'Amount received' => self::money((int) $payment['received_cents'], $order),
                'Still to pay' => $outstanding > 0 ? self::money($outstanding, $order) : null,
            ],
            'paragraphs' => $paragraphs,
            'button' => ['label' => 'View your order', 'url' => $this->links($order['order_no'], 0, 'stock')['order']],
        ], [['name' => OrderPdfs::receiptFilename($payment['receipt_no']), 'content' => $receiptPdf, 'type' => 'application/pdf']]);
    }

    /**
     * @param array<string, mixed> $order buyer's view
     * @param array<string, mixed> $payment
     */
    public function paymentRejected(array $order, array $payment): void
    {
        $this->toBuyer($order, "We could not confirm your payment for {$order['order_no']}", [
            'title' => 'We could not confirm your payment',
            'intro' => sprintf('You reported a %s of %s (transaction %s), but we could not match it with our %s.', self::isStock($order) ? 'payment' : 'transfer', self::money((int) $payment['amount_cents'], $order), $payment['transaction_id'], self::isStock($order) ? 'accounts' : 'bank statement'),
            'facts' => ['Reason' => (string) $payment['rejection_reason']],
            'paragraphs' => ['Please check the details with your bank and report the transfer again, or reply to this email and we will help.'],
            'button' => $order['can_submit_payment'] ? ['label' => 'Report the payment again', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['confirm_payment']] : null,
        ]);
    }

    /**
     * A production step for the buyer (when staff choose to tell them).
     *
     * @param array<string, mixed> $order buyer's view
     */
    public function productionUpdate(array $order, string $stageLabel, bool $done, int $photos, ?string $note): void
    {
        $title = $done ? "{$stageLabel} is done" : "{$stageLabel} has started";
        $paragraphs = [];
        if ($note !== null && $note !== '') {
            $paragraphs[] = $note;
        }
        if ($photos > 0) {
            $paragraphs[] = $photos === 1 ? 'A new photo is on your order page.' : "{$photos} new photos are on your order page.";
        }
        $due = $order['production_due_on'] !== null ? Format::date($order['production_due_on']) : null;

        $this->toBuyer($order, "{$title}: order {$order['order_no']}", [
            'title' => $title,
            'intro' => "Here is an update on the production of your order.",
            'facts' => ['Step' => $stageLabel, 'Due to be ready' => $due],
            'paragraphs' => $paragraphs,
            'button' => ['label' => 'See the progress and photos', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['order']],
        ]);
    }

    /**
     * Production is done: the balance invoice (PDF) goes to the buyer with the bank details.
     *
     * @param array<string, mixed> $order buyer's view (with bank details)
     */
    public function readyToShip(array $order, string $invoicePdf): void
    {
        $due = $order['amounts']['due_now_cents'];
        $this->toBuyer($order, "Your order {$order['order_no']} is ready to ship", [
            'title' => 'Your order is ready to ship',
            'intro' => $due > 0
                ? sprintf('Production and the final quality check are done. Please pay the balance of %s, so we can ship your order. The invoice is attached.', Format::money($due))
                : 'Production and the final quality check are done, and your order is paid in full. We are booking the shipment now.',
            'facts' => [
                'Invoice' => (string) $order['balance_invoice_no'],
                'Balance due' => Format::money($due),
                'Shipping' => $order['shipping_mode_label'],
                'Payment reference' => $due > 0 ? $order['order_no'] : null,
            ],
            'paragraphs' => $due > 0 ? ['After the transfer, tell us the transaction ID on the Confirm payment page.'] : [],
            'bank' => $due > 0 ? $this->bankOrNull($order) : false,
            'button' => $due > 0
                ? ['label' => 'Confirm payment', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['confirm_payment']]
                : ['label' => 'View your order', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['order']],
        ], [['name' => OrderPdfs::invoiceFilename((string) $order['balance_invoice_no']), 'content' => $invoicePdf, 'type' => 'application/pdf']]);
    }

    /** @param array<string, mixed> $order buyer's view (with the shipment) */
    public function shipped(array $order): void
    {
        $shipment = $order['shipment'] ?? [];
        if (self::isStock($order)) {
            $this->toBuyer($order, "Your order {$order['order_no']} is on its way", [
                'title' => 'Your order is on its way',
                'intro' => sprintf('We sent your order by %s to %s.', $shipment['carrier'] ?? 'cargo', $order['address']['city'] ?? 'your city'),
                'facts' => [
                    'Sent on' => isset($shipment['shipped_on']) ? Format::date($shipment['shipped_on']) : null,
                    'Cargo company' => $shipment['carrier'] ?? null,
                    'Bilty / tracking number' => $shipment['tracking_no'] ?? null,
                    'Cartons' => isset($shipment['cartons']) ? (string) $shipment['cartons'] : null,
                ],
                'paragraphs' => [
                    'Pay the cargo charges to the cargo company when you collect your goods.',
                    'If there is an issue with your order, tell us within 7 days of receiving it.',
                ],
                'button' => ['label' => 'View your order', 'url' => $this->links($order['order_no'], 0, 'stock')['order']],
            ]);

            return;
        }
        $sample = self::isSample($order);
        $this->toBuyer($order, $sample ? "Your samples for order {$order['order_no']} are on their way" : "Your order {$order['order_no']} has shipped", [
            'title' => $sample ? 'Your samples are on their way' : 'Your order is on its way',
            'intro' => $sample
                ? sprintf('We handed your samples to %s.', $shipment['carrier'] ?? 'the courier')
                : sprintf('We handed your order to %s. The shipping documents are on your order page.', $shipment['carrier'] ?? 'the carrier'),
            'facts' => [
                'Shipped on' => isset($shipment['shipped_on']) ? Format::date($shipment['shipped_on']) : null,
                'Carrier' => $shipment['carrier'] ?? null,
                'Tracking number' => $shipment['tracking_no'] ?? null,
                'Cartons' => isset($shipment['cartons']) ? (string) $shipment['cartons'] : null,
            ],
            'paragraphs' => !empty($shipment['tracking_url']) ? ['Track the shipment: ' . $shipment['tracking_url']] : [],
            'button' => ['label' => 'Documents and tracking', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['order']],
        ]);
    }

    /** @param array<string, mixed> $order buyer's view */
    public function delivered(array $order): void
    {
        if (self::isStock($order)) {
            $collected = $order['shipping_mode'] === 'pickup';
            $this->toBuyer($order, $collected ? "Thank you for collecting order {$order['order_no']}" : "Your order {$order['order_no']} was delivered", [
                'title' => $collected ? 'Thank you for your order' : 'Your order was delivered',
                'intro' => $collected ? 'You collected your order. We hope the jeans sell well.' : 'Your order has arrived. We hope the jeans sell well.',
                'paragraphs' => ['If there is an issue with your order, such as a fault, a wrong size or a wrong color, tell us within 7 days and send us photos.'],
                'button' => ['label' => 'See what is in stock', 'url' => rtrim($this->config->string('web.url'), '/') . '/stock'],
            ]);

            return;
        }
        if (self::isSample($order)) {
            $this->toBuyer($order, "Your samples for order {$order['order_no']} were delivered", [
                'title' => 'Your samples were delivered',
                'intro' => 'We hope they look and fit the way you want. When you are ready, order the styles in the shop, or reply to this email with any changes you would like.',
                'button' => ['label' => 'Go to the shop', 'url' => rtrim($this->config->string('web.url'), '/') . '/wholesale-shop'],
            ]);

            return;
        }
        $this->toBuyer($order, "Your order {$order['order_no']} was delivered", [
            'title' => 'Your order was delivered',
            'intro' => 'Thank you for ordering from PK Apparel. We hope the goods sell well. If anything is not right, reply to this email and we will sort it out.',
            'button' => ['label' => 'View your order', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['order']],
        ]);
    }

    /**
     * A week after delivery: the buyer is asked for a review (the link works without logging in).
     *
     * @param array<string, mixed> $order buyer's view
     */
    public function reviewRequest(array $order, string $token): void
    {
        $url = rtrim($this->config->string('web.url'), '/') . '/review?token=' . $token;
        $this->toBuyer($order, "How did order {$order['order_no']} go?", [
            'title' => 'How did your order go?',
            'intro' => 'Your order was delivered a week ago. Would you tell other buyers how it went? It takes a minute, and it helps us a lot.',
            'paragraphs' => [
                'You choose whether your review shows your company name, or only your type of business and country.',
                "If the button does not work, copy this address into your browser: {$url}",
            ],
            'button' => ['label' => 'Write a review', 'url' => $url],
        ]);
    }

    /**
     * Staff are told about a new review, which waits for their approval.
     *
     * @param array<string, mixed> $staffView
     */
    public function reviewSubmitted(array $staffView, int $rating, string $body): void
    {
        $web = rtrim($this->config->string('web.url'), '/');
        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] New review from %s: %d of 5', $staffView['order_no'], $staffView['customer']['company_name'], $rating),
            $this->mailer->render('order-update', [
                'order' => $staffView,
                'title' => 'A buyer reviewed their order',
                'intro' => $body,
                'facts' => ['Order' => $staffView['order_no'], 'Rating' => str_repeat('★', $rating) . str_repeat('☆', 5 - $rating)],
                'paragraphs' => ['The review appears on the website once you approve it in the admin (Buyer reviews).'],
                'button' => ['label' => 'Review it in the admin', 'url' => "{$web}/admin/reviews?tab=orders"],
                'bank' => false,
                'preheader' => 'A review waits for your approval.',
            ] + $this->layoutData()),
            (string) $staffView['customer']['email'],
        );
    }

    /**
     * A status change explained to the buyer: on hold, resumed, cancelled by us, new due date.
     *
     * @param array<string, mixed> $order buyer's view
     */
    public function notice(array $order, string $title, string $message, ?string $reason = null): void
    {
        $this->toBuyer($order, "{$title}: order {$order['order_no']}", [
            'title' => $title,
            'intro' => $message,
            'facts' => ['Reason' => $reason],
            'button' => ['label' => 'View your order', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['order']],
        ]);
    }

    /**
     * A reminder for an unpaid order (the second one says when it will be cancelled).
     *
     * @param array<string, mixed> $order buyer's view (with bank details)
     */
    public function paymentReminder(array $order, int $number, string $cancelOn): void
    {
        if (self::isStock($order)) {
            $due = self::money($order['amounts']['due_now_cents'], $order);
            $this->toBuyer($order, "Reminder: payment for order {$order['order_no']}", [
                'title' => 'Your order is waiting for the payment',
                'intro' => sprintf('We have not received the payment of %s for your order yet. Your sets are only held for you once it is confirmed, so they may sell out.', $due),
                'facts' => ['Payment due' => $due, 'Payment reference' => $order['order_no']],
                'paragraphs' => [
                    sprintf('If we do not receive it by %s, the order is cancelled automatically.', Format::date($cancelOn)),
                    'Already paid? Tell us the transaction ID on the Confirm payment page. Changed your mind? You can cancel the order on your order page.',
                ],
                'stockPayment' => $this->bankOrNull($order) ?? [],
                'button' => ['label' => 'Confirm payment', 'url' => $this->links($order['order_no'], 0, 'stock')['confirm_payment']],
            ]);

            return;
        }
        $sample = self::isSample($order);
        $due = Format::money($order['amounts']['due_now_cents']);
        $this->toBuyer($order, $sample ? "Reminder: payment for sample order {$order['order_no']}" : "Reminder: deposit for order {$order['order_no']}", [
            'title' => $sample ? 'Your sample order is waiting for the payment' : 'Your order is waiting for the deposit',
            'intro' => $sample
                ? sprintf('We have not received the payment of %s for your sample order yet. We prepare your samples as soon as it arrives.', $due)
                : sprintf('We have not received the deposit of %s for your order yet. Production starts as soon as it arrives.', $due),
            'facts' => [($sample ? 'Payment due' : 'Deposit due') => $due, 'Payment reference' => $order['order_no']],
            'paragraphs' => array_values(array_filter([
                $number >= 2 ? sprintf('If we do not receive it by %s, the order is cancelled automatically. If you have already paid, please report the transfer so we can match it.', Format::date($cancelOn)) : null,
                'Already paid? Tell us the transaction ID on the Confirm payment page. Changed your mind? You can cancel the order on your order page.',
            ])),
            'bank' => $this->bankOrNull($order),
            'button' => ['label' => 'Confirm payment', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['confirm_payment']],
        ]);
    }

    /** @param array<string, mixed> $order buyer's view */
    public function autoCancelled(array $order): void
    {
        if (self::isStock($order)) {
            $this->toBuyer($order, "Order {$order['order_no']} was cancelled", [
                'title' => 'Your order was cancelled',
                'intro' => sprintf('We did not receive the payment within %d days, so the order was cancelled automatically. Nothing else needs to be done.', $this->stock->paymentDays()),
                'paragraphs' => ['If you still want these jeans, place a new order while they are in stock, or reply to this email and we will help.'],
                'button' => ['label' => 'See what is in stock', 'url' => rtrim($this->config->string('web.url'), '/') . '/stock'],
            ]);

            return;
        }
        $this->toBuyer($order, "Order {$order['order_no']} was cancelled", [
            'title' => 'Your order was cancelled',
            'intro' => sprintf('We did not receive the %s within 30 days, so the order was cancelled automatically. Nothing else needs to be done.', self::isSample($order) ? 'payment' : 'deposit'),
            'paragraphs' => ['If you still want these goods, place a new order in the shop, or reply to this email and we will help.'],
            'button' => ['label' => 'Go to the shop', 'url' => rtrim($this->config->string('web.url'), '/') . '/wholesale-shop'],
        ]);
    }

    /**
     * The morning summary for staff.
     *
     * @param array<string, mixed> $summary
     */
    public function staffSummary(array $summary): void
    {
        $web = rtrim($this->config->string('web.url'), '/');
        $parts = array_filter([
            $summary['payments'] !== [] ? count($summary['payments']) . ' to check' : null,
            ($summary['toPrice'] ?? []) !== [] ? count($summary['toPrice']) . ' to price' : null,
            ($summary['reviews'] ?? 0) > 0 ? $summary['reviews'] . ($summary['reviews'] === 1 ? ' review' : ' reviews') . ' to approve' : null,
            $summary['late'] !== [] ? count($summary['late']) . ' late' : null,
            $summary['cancelled'] !== [] ? count($summary['cancelled']) . ' cancelled' : null,
        ]);
        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            'PK Apparel today: ' . ($parts === [] ? 'orders due soon' : implode(', ', $parts)),
            $this->mailer->render('staff-summary', $this->layoutData() + $summary + [
                'links' => ['admin' => "{$web}/admin"],
                'preheader' => 'Payments to check, late orders and orders due soon.',
            ]),
        );
    }

    /**
     * @param array<string, mixed> $order buyer's view
     * @param array<string, mixed> $content order-update template variables
     * @param list<array{name: string, content: string, type: string}> $attachments
     */
    private function toBuyer(array $order, string $subject, array $content, array $attachments = []): void
    {
        $this->mailer->send(
            (string) $order['customer']['email'],
            "{$subject} | PK Apparel",
            // The content's own values (e.g. a bank block) come first; the rest are defaults.
            $this->mailer->render('order-update', $content + ['order' => $order, 'bank' => false, 'stockPayment' => false, 'preheader' => $content['intro']] + $this->layoutData($order)),
            $this->config->string('mail.admin_to'),
            $attachments,
        );
    }

    /**
     * A priced sample order: the sample invoice (attached), the amount, the bank details and the next steps.
     *
     * @param array<string, mixed> $order buyer's view (with bank details)
     */
    private function sampleInvoice(array $order, string $subject, string $title): void
    {
        $amounts = $order['amounts'];
        $pdf = $this->pdfs->proforma($order, $this->bankOrNull($order));
        $this->toBuyer($order, $subject, [
            'title' => $title,
            'intro' => sprintf('Please pay %s by bank transfer, so we can prepare your samples. Your sample invoice is attached.', Format::money($amounts['due_now_cents'])),
            'facts' => [
                'Samples' => sprintf('%d pcs, %s', $order['total_pcs'], Format::money($amounts['goods_cents'] + $amounts['addons_cents'])),
                'Courier' => $amounts['freight_cents'] === null ? null : Format::money((int) $amounts['freight_cents']),
                'Total' => Format::money($amounts['total_cents']),
                'Payment reference' => $order['order_no'],
            ],
            'paragraphs' => [
                sprintf('After the transfer, tell us the transaction ID on the Confirm payment page. Your samples are ready about %d days after the payment is confirmed, then go by express courier with tracking.', $order['lead_time_days']),
            ],
            'bank' => $this->bankOrNull($order),
            'button' => ['label' => 'Confirm payment', 'url' => $this->links($order['order_no'], 0, $order['kind'] ?? 'bulk')['confirm_payment']],
        ], [['name' => OrderPdfs::proformaFilename($order['order_no'], 'sample'), 'content' => $pdf, 'type' => 'application/pdf']]);
    }

    /** @param array<string, mixed> $order */
    private static function isSample(array $order): bool
    {
        return ($order['kind'] ?? 'bulk') === 'sample';
    }

    /**
     * "the courier to Germany and your custom-design sample": what staff still have to price.
     *
     * @param array<string, mixed> $order presented order
     */
    private static function toPrice(array $order): string
    {
        $parts = [];
        if ($order['amounts']['freight_status'] === 'quoted') {
            $parts[] = 'the courier to ' . Format::country($order['address']['country_code'] ?? null);
        }
        if ($order['amounts']['has_quoted_addons']) {
            $parts[] = 'your custom-design sample';
        }

        return $parts === [] ? 'your samples' : implode(' and ', $parts);
    }

    /** @param array<string, mixed> $order presented order */
    private static function destination(array $order): string
    {
        return trim(($order['address']['city'] ?? '') . ', ' . Format::country($order['address']['country_code'] ?? null), ', ');
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function bankOrNull(array $order): ?array
    {
        return is_array($order['bank'] ?? null) ? $order['bank'] : null;
    }

    /** @return array<string, string> The buyer's order pages (under /stock for stock orders) and the admin's. */
    private function links(string $orderNo, int $orderId, string $kind = 'bulk'): array
    {
        $web = rtrim($this->config->string('web.url'), '/');
        $account = $kind === 'stock' ? "{$web}/stock/account" : "{$web}/account";

        return [
            'order' => "{$account}/orders/{$orderNo}",
            'confirm_payment' => "{$account}/orders/{$orderNo}/confirm-payment",
            'admin' => "{$web}/admin/orders/{$orderId}",
        ];
    }

    /**
     * The layout's contacts, and its stock variant for stock orders (stock tagline, no country).
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function layoutData(array $order = []): array
    {
        return [
            'contact' => $this->settings->get('contact', []),
            'addresses' => $this->settings->get('addresses', []),
            'stock' => self::isStock($order),
        ];
    }

    /**
     * A staff notification about a stock order (stock-staff template).
     *
     * @param array<string, mixed> $order staff view
     * @param array<string, mixed> $content title, intro, facts, lines (bool: list the order's lines)
     * @param array<string, string> $links
     */
    private function toStaff(array $order, string $subject, array $content, array $links): void
    {
        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            $subject,
            $this->mailer->render('stock-staff', $content + ['order' => $order, 'links' => $links, 'preheader' => $content['intro']] + $this->layoutData($order)),
            (string) $order['customer']['email'],
        );
    }
}
