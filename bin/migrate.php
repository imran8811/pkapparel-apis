<?php

declare(strict_types=1);

/*
 * Apply pending database migrations:  php bin/migrate.php
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::create(dirname(__DIR__));
$migrator = new Migrator($app->container->get(Database::class), $app->basePath . '/database/migrations');

$count = $migrator->run(static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
});

fwrite(STDOUT, $count === 0 ? 'Nothing to migrate.' . PHP_EOL : "Done: {$count} migration(s) applied." . PHP_EOL);
