<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Settings;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The PDFs, made with dompdf from templates/pdf: the order's proforma invoice (the order number is the PI number),
 * balance invoice and payment receipts, made from the order's frozen data, and the invoices staff make in the admin.
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
        $title = (($order['kind'] ?? 'bulk') === 'sample' ? 'Sample invoice ' : 'Proforma invoice ') . $order['order_no'];

        return $this->render('proforma-invoice', ['order' => $order, 'bank' => $bank, 'document' => ['kind' => 'proforma']], $title);
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
        // Stock orders: in rupees, with the sets, and without the country or tax numbers.
        $template = ($order['kind'] ?? 'bulk') === 'stock' ? 'stock-receipt' : 'receipt';

        return $this->render($template, ['order' => $order, 'payment' => $payment], 'Receipt ' . $payment['receipt_no']);
    }

    /**
     * An invoice made in Admin → Invoices (sample, proforma or final), with the company and bank details.
     *
     * @param array<string, mixed> $invoice presented invoice (Invoices::present)
     * @param array<string, mixed>|null $bank bank account for the transfer
     */
    public function invoice(array $invoice, ?array $bank): string
    {
        // The footer says "Page 1 of 2": dompdf cannot count the pages in CSS, so a longer invoice is made again
        // with its page count (most invoices fit on one page and are made once).
        $title = $invoice['type_label'] . ' ' . $invoice['invoice_no'];
        $pdf = $this->dompdf('invoice', ['invoice' => $invoice, 'bank' => $bank, 'pages' => 1], $title);
        $pages = $pdf->getCanvas()->get_page_count();
        if ($pages !== 1) {
            $pdf = $this->dompdf('invoice', ['invoice' => $invoice, 'bank' => $bank, 'pages' => $pages], $title);
        }

        return (string) $pdf->output();
    }

    /** "PI-PK-261016-482.pdf", or "Sample-invoice-PK-261016-482.pdf" for a sample order. */
    public static function proformaFilename(string $orderNo, string $kind = 'bulk'): string
    {
        return ($kind === 'sample' ? 'Sample-invoice-' : 'PI-') . $orderNo . '.pdf';
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
        return (string) $this->dompdf($template, $data, $title)->output();
    }

    /** @param array<string, mixed> $data */
    private function dompdf(string $template, array $data, string $title): Dompdf
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
            'website' => preg_replace('#^https?://#', '', rtrim($this->config->string('web.url', 'https://www.pkapparel.com'), '/')),
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

        return $pdf;
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
