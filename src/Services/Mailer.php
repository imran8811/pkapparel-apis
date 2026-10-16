<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Sends email through SMTP (PHPMailer), or writes it to storage/logs/mail-*.log
 * when MAIL_DRIVER=log (local development). Sending never throws: failures are
 * logged and reported with a false return value.
 */
final class Mailer
{
    public function __construct(private readonly Config $config, private readonly Logger $logger)
    {
    }

    /**
     * @param string|list<string> $to
     * @param list<array{name: string, content: string, type: string}> $attachments e.g. a proforma invoice PDF
     */
    public function send(string|array $to, string $subject, string $html, ?string $replyTo = null, array $attachments = []): bool
    {
        $recipients = array_values(array_filter((array) $to));
        if ($recipients === []) {
            return false;
        }

        if ($this->config->string('mail.driver', 'log') === 'log') {
            return $this->writeToLog($recipients, $subject, $html, $replyTo, $attachments);
        }
        if (!$this->smtpConfigured()) {
            // Never lose a message because of missing settings: keep it in the mail log instead.
            $this->logger->warning('SMTP is not fully configured (MAIL_HOST/USERNAME/PASSWORD); email written to the mail log', [
                'to' => $recipients,
                'subject' => $subject,
            ]);

            return $this->writeToLog($recipients, $subject, $html, $replyTo, $attachments);
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->config->string('mail.host');
            $mail->Port = (int) $this->config->get('mail.port', 587);
            $mail->SMTPAuth = $this->config->string('mail.username') !== '';
            $mail->Username = $this->config->string('mail.username');
            $mail->Password = $this->config->string('mail.password');
            $mail->SMTPSecure = $this->config->string('mail.encryption') === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 15;

            $mail->setFrom($this->config->string('mail.from_address'), $this->config->string('mail.from_name'));
            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient);
            }
            if ($replyTo !== null && $replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            foreach ($attachments as $attachment) {
                $mail->addStringAttachment($attachment['content'], $attachment['name'], PHPMailer::ENCODING_BASE64, $attachment['type']);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = self::toText($html);
            $mail->send();

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Email could not be sent', [
                'to' => $recipients,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function smtpConfigured(): bool
    {
        return $this->config->string('mail.host') !== ''
            && $this->config->string('mail.username') !== ''
            && $this->config->string('mail.password') !== '';
    }

    /**
     * Render a template from templates/email/{name}.php inside the shared layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data): string
    {
        $directory = $this->config->string('paths.templates') . '/email';
        $content = self::renderFile("{$directory}/{$template}.php", $data);

        return self::renderFile("{$directory}/layout.php", $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    private static function renderFile(string $file, array $data): string
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

    /**
     * @param list<string> $recipients
     * @param list<array{name: string, content: string, type: string}> $attachments
     */
    private function writeToLog(array $recipients, string $subject, string $html, ?string $replyTo, array $attachments): bool
    {
        $directory = $this->config->string('paths.storage') . '/logs';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $files = array_map(static fn (array $a): string => sprintf('%s (%s, %d bytes)', $a['name'], $a['type'], strlen($a['content'])), $attachments);
        $entry = sprintf(
            "==== %s ====\nTo: %s\nReply-To: %s\nSubject: %s\n%s\n%s\n\n",
            gmdate('Y-m-d H:i:s \U\T\C'),
            implode(', ', $recipients),
            $replyTo ?? '-',
            $subject,
            $files === [] ? '' : 'Attachments: ' . implode(', ', $files) . "\n",
            self::toText($html),
        );

        return file_put_contents($directory . '/mail-' . gmdate('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    private static function toText(string $html): string
    {
        $text = (string) preg_replace(['/<(br|\/p|\/h[1-6]|\/tr|\/li)[^>]*>/i', '/<li[^>]*>/i'], ["\n", '- '], $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace(["/[ \t]+/", "/\n\s*\n\s*\n+/"], [' ', "\n\n"], $text));
    }
}
