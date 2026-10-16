<?php

declare(strict_types=1);

/*
 * PK Apparel API: front controller. Every request is routed through here
 * (see .htaccess). Nothing else in this folder is PHP.
 */

use App\Core\App;

// PHP's built-in dev server (php -S) routes everything here: let it serve real files such as uploads.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    App::create(dirname(__DIR__))->run();
} catch (Throwable $e) {
    // Only reached if booting itself fails (missing config, broken .env…).
    error_log('[pkapparel-api] Boot failure: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":{"code":"server_error","message":"The service is temporarily unavailable."}}';
}
