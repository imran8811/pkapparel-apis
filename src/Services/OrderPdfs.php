<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Settings;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The order's PDFs, made with dompdf from templates/pdf and the order's frozen data:
 * the proforma invoice (the order number is the PI number), the balance invoice and payment receipts.
 */
final class OrderPdfs
{
    public function __construct(private readonly Config $config, private readonly Settings $settings)
    {
    }

    /**
     * @param array<string, mixed> $order presented order (OrderService::present)
     * @param array<string, mixed>|null $bank bank account for the transfer
     */
    public function proforma(array $order, ?array $bank): string
    {
        return $this->render('proforma-invoice', ['order' => $order, 'bank' => $bank, 'document' => ['kind' => 'proforma']], 'Proforma invoice ' . $order['order_no']);
    }

    /**
     * The invoice for the balance, issued when the order is ready to ship (it lists the payments received).
     *
     * @param array<string, mixed> $order presented order with balance_invoice_no
     * @param array<string, mixed>|null $bank
     */
    public function balanceInvoice(array $order, ?array $bank): string
    {
        return $this->render('proforma-invoice', [
            'order' => $order,
            'bank' => $bank,
            'document' => ['kind' => 'balance', 'number' => $order['balance_invoice_no'], 'date' => $order['balance_invoiced_at']],
        ], 'Invoice ' . $order['balance_invoice_no']);
    }

    /**
     * @param array<string, mixed> $order presented order
     * @param array<string, mixed> $payment presented payment (confirmed, with a receipt number)
     */
    public function receipt(array $order, array $payment): string
    {
        return $this->render('receipt', ['order' => $order, 'payment' => $payment], 'Receipt ' . $payment['receipt_no']);
    }

    public static function proformaFilename(string $orderNo): string
    {
        return 'PI-' . $orderNo . '.pdf';
    }

    public static function invoiceFilename(string $invoiceNo): string
    {
        return $invoiceNo . '.pdf';
    }

    public static function receiptFilename(string $receiptNo): string
    {
        return $receiptNo . '.pdf';
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data, string $title): string
    {
        $templates = rtrim($this->config->string('paths.templates'), '/\\') . '/pdf';
        $cache = rtrim($this->config->string('paths.storage'), '/\\') . '/cache/dompdf';
        if (!is_dir($cache)) {
            mkdir($cache, 0775, true);
        }

        $html = self::renderTemplate("{$templates}/{$template}.php", $data + [
            'company' => $this->settings->get('company', []),
            'contact' => $this->settings->get('contact', []),
            'addresses' => $this->settings->get('addresses', []),
            // The owner's original logo file (129×136 px is about 280 dpi at the printed size).
            'logo' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($templates . '/logo.png')),
        ]);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setChroot([$templates]);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->addInfo('Title', $title);
        $pdf->addInfo('Author', 'PK Apparel');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** @param array<string, mixed> $data */
    private static function renderTemplate(string $file, array $data): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
