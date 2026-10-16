<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Settings;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The proforma invoice (PI) as a PDF, made with dompdf from the order's frozen data,
 * so the same order always gives the same document. The order number is the PI number.
 */
final class ProformaInvoice
{
    public function __construct(private readonly Config $config, private readonly Settings $settings)
    {
    }

    /**
     * @param array<string, mixed> $order presented order (OrderService::present)
     * @param array<string, mixed>|null $bank bank account for the transfer
     */
    public function render(array $order, ?array $bank): string
    {
        $templates = rtrim($this->config->string('paths.templates'), '/\\') . '/pdf';
        $cache = rtrim($this->config->string('paths.storage'), '/\\') . '/cache/dompdf';
        if (!is_dir($cache)) {
            mkdir($cache, 0775, true);
        }

        $html = self::renderTemplate($templates . '/proforma-invoice.php', [
            'order' => $order,
            'bank' => $bank,
            'company' => $this->settings->get('company', []),
            'contact' => $this->settings->get('contact', []),
            'addresses' => $this->settings->get('addresses', []),
            'logo' => 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents($templates . '/logo.svg')),
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
        $pdf->addInfo('Title', 'Proforma invoice ' . $order['order_no']);
        $pdf->addInfo('Author', 'PK Apparel');
        $pdf->render();

        return (string) $pdf->output();
    }

    public static function filename(string $orderNo): string
    {
        return 'PI-' . $orderNo . '.pdf';
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
