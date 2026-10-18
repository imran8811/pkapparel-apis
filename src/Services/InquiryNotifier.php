<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Inquiry;
use App\Models\Settings;

/** Emails sent when a website inquiry arrives: a notification to staff and an acknowledgement to the sender. */
final class InquiryNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Settings $settings,
    ) {
    }

    /** @param array<string, mixed> $inquiry with "files" (attached uploads) when there are any */
    public function notify(int $id, array $inquiry): void
    {
        $typeLabel = Inquiry::TYPES[$inquiry['type']] ?? 'Message';
        $reference = Inquiry::reference($id);
        $data = [
            'inquiry' => $inquiry,
            'files' => $inquiry['files'] ?? [],
            'typeLabel' => $typeLabel,
            'reference' => $reference,
            'adminUrl' => rtrim($this->config->string('web.url'), '/') . "/admin/inquiries/{$id}",
            'contact' => $this->settings->get('contact', []),
            'addresses' => $this->settings->get('addresses', []),
        ];

        $company = trim((string) ($inquiry['company'] ?? ''));
        $this->mailer->send(
            $this->config->string('mail.admin_to'),
            sprintf('[%s] %s from %s%s', $reference, $typeLabel, $inquiry['name'], $company !== '' ? " ({$company})" : ''),
            $this->mailer->render('inquiry-admin', $data + ['preheader' => mb_substr((string) $inquiry['message'], 0, 120)]),
            (string) $inquiry['email'],
        );

        $this->mailer->send(
            (string) $inquiry['email'],
            "We received your message ({$reference}) | PK Apparel",
            $this->mailer->render('inquiry-customer', $data + ['preheader' => 'Thank you for contacting PK Apparel.']),
            $this->config->string('mail.admin_to'),
        );
    }
}
