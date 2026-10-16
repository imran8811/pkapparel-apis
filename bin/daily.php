<?php

declare(strict_types=1);

/*
 * The daily job: payment reminders for unpaid orders (after 3 and 7 days), cancelling orders still
 * unpaid after 30 days, and the morning summary for staff. Run it once a day from a cron job, e.g.
 *
 *     0 7 * * *  php /home/<account>/api/bin/daily.php
 *
 * Running it again on the same day sends nothing twice.
 */

use App\Core\App;
use App\Services\DailyJob;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::create(dirname(__DIR__));
$result = $app->container->get(DailyJob::class)->run();

printf(
    "%s  reminders sent: %d, orders cancelled: %d, staff summary: %s\n",
    gmdate('Y-m-d H:i \U\T\C'),
    $result['reminded'],
    $result['cancelled'],
    $result['summary_sent'] ? 'sent' : 'nothing to report',
);
