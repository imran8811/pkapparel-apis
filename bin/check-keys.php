<?php

declare(strict_types=1);

/*
 * Shows which shared secrets this API has loaded from .env, without revealing them: the length and a short
 * fingerprint (the start of a SHA-256 hash). When the website reports "This API only accepts requests from the
 * PK Apparel website", its build log shows the same fingerprint for its API_CLIENT_KEY: the two must be equal.
 *
 *     php bin/check-keys.php
 */

use App\Core\App;
use App\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = App::create(dirname(__DIR__))->container->get(Config::class);

foreach (['CLIENT_KEY (website: API_CLIENT_KEY)' => 'client_key', 'REVALIDATE_SECRET (website: REVALIDATE_SECRET)' => 'web.revalidate_secret'] as $label => $key) {
    $value = $config->string($key);
    echo str_pad($label, 48), $value === '' ? 'empty' : sprintf('%d characters, fingerprint %s', strlen($value), substr(hash('sha256', $value), 0, 8)), PHP_EOL;
}
