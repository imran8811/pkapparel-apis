<?php

declare(strict_types=1);

/*
 * Check the email settings in .env by sending a test message:
 *   php bin/send-test-email.php you@example.com
 */

use App\Core\App;
use App\Core\Config;
use App\Services\Mailer;

require dirname(__DIR__) . '/vendor/autoload.php';

$to = $argv[1] ?? '';
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, 'Usage: php bin/send-test-email.php you@example.com' . PHP_EOL);
    exit(1);
}

$app = App::create(dirname(__DIR__));
$config = $app->container->get(Config::class);
$mailer = $app->container->get(Mailer::class);

$driver = $config->string('mail.driver', 'log');
if ($driver !== 'smtp') {
    fwrite(STDERR, "MAIL_DRIVER is \"{$driver}\": emails are only written to storage/logs/mail-*.log. Set MAIL_DRIVER=smtp to send." . PHP_EOL);
    exit(1);
}
if (!$mailer->smtpConfigured()) {
    fwrite(STDERR, 'SMTP settings are incomplete: fill in MAIL_HOST, MAIL_USERNAME and MAIL_PASSWORD in api/.env.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf('Sending through %s:%s as %s…', $config->string('mail.host'), $config->string('mail.port'), $config->string('mail.username')) . PHP_EOL);

$html = $mailer->render('test', ['sentAt' => gmdate('Y-m-d H:i:s') . ' UTC', 'preheader' => 'Email settings work.']);
$sent = $mailer->send($to, 'Test email from the PK Apparel website', $html);

if ($sent) {
    fwrite(STDOUT, "Sent. Check the inbox (and spam folder) of {$to}." . PHP_EOL);
    exit(0);
}

fwrite(STDERR, 'Not sent. The reason is in storage/logs/api-' . gmdate('Y-m-d') . '.log' . PHP_EOL);
exit(1);
