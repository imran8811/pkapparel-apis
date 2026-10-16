<?php

declare(strict_types=1);

/*
 * Ask the website to refresh cached content by tag:
 *   php bin/revalidate.php site
 *   php bin/revalidate.php products product:pk-1058
 */

use App\Core\App;
use App\Services\WebRevalidator;

require dirname(__DIR__) . '/vendor/autoload.php';

$tags = array_values(array_slice($argv, 1));
if ($tags === []) {
    fwrite(STDERR, 'Usage: php bin/revalidate.php <tag> [<tag> ...]' . PHP_EOL);
    exit(1);
}

$app = App::create(dirname(__DIR__));
$ok = $app->container->get(WebRevalidator::class)->revalidate($tags);

fwrite($ok ? STDOUT : STDERR, ($ok ? 'Revalidated: ' : 'Failed to revalidate (see storage/logs): ') . implode(', ', $tags) . PHP_EOL);
exit($ok ? 0 : 1);
