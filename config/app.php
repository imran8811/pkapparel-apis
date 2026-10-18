<?php

declare(strict_types=1);

use App\Core\Env;

$base = dirname(__DIR__);

return [
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG'),
    'url' => Env::get('APP_URL', 'https://api.pkapparel.com'),

    // Set when the API is served from a sub-folder, e.g. "/api" on shared hosting.
    'base_path' => Env::get('APP_BASE_PATH', ''),

    // Shared secret: the website sends it in X-Client-Key with every request.
    'client_key' => Env::get('CLIENT_KEY', ''),

    'web' => [
        'url' => Env::get('WEB_URL', 'https://www.pkapparel.com'),
        'revalidate_secret' => Env::get('REVALIDATE_SECRET', ''),
        // Set WEB_REVALIDATE=false to skip cache refresh calls (tests, scripts without a running website).
        'revalidate' => Env::bool('WEB_REVALIDATE', true),
    ],

    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'name' => Env::get('DB_NAME', 'pkapparel'),
        'user' => Env::get('DB_USER', 'pkapparel'),
        'password' => Env::get('DB_PASSWORD', ''),
    ],

    'mail' => [
        // "log" writes emails to storage/logs/mail-*.log (development); "smtp" sends them.
        'driver' => Env::get('MAIL_DRIVER', 'log'),
        'host' => Env::get('MAIL_HOST', ''),
        'port' => Env::int('MAIL_PORT', 587),
        'username' => Env::get('MAIL_USERNAME', ''),
        'password' => Env::get('MAIL_PASSWORD', ''),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
        'from_address' => Env::get('MAIL_FROM_ADDRESS', 'info@pkapparel.com'),
        'from_name' => Env::get('MAIL_FROM_NAME', 'PK Apparel'),
        // Where staff notifications (new inquiries, orders…) are sent.
        'admin_to' => Env::get('MAIL_ADMIN_TO', 'info@pkapparel.com'),
    ],

    'paths' => [
        'base' => $base,
        'storage' => $base . '/storage',
        'templates' => $base . '/templates',
        'uploads' => $base . '/public/uploads',
    ],

    // The old website's database, read only by the one-time import scripts.
    'legacy_db' => [
        'name' => Env::get('LEGACY_DB_NAME', 'pkappar2_wholesale_2166718'),
        'uploads' => Env::get('LEGACY_UPLOADS_DIR', 'D:/xampp/htdocs/pkapparel/uploads'),
        'site_dir' => Env::get('LEGACY_SITE_DIR', 'D:/xampp/htdocs/pkapparel'),
    ],
];
