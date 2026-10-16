<?php

declare(strict_types=1);

/*
 * Admin panel accounts.
 *
 *   php bin/create-staff.php --name="Imran" --email=you@example.com --role=admin
 *   php bin/create-staff.php --reset-password --email=you@example.com
 *   php bin/create-staff.php --deactivate --email=you@example.com   (and --activate)
 *   php bin/create-staff.php --list
 *
 * Roles: admin (everything), editor (website content), sales (inquiries; orders later),
 * production (production updates, later). A new or reset password is generated and
 * printed once. Ask the person to change it under "Account" after logging in.
 */

use App\Core\App;
use App\Core\Database;
use App\Models\StaffUser;
use App\Services\TokenService;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['name:', 'email:', 'role:', 'reset-password', 'deactivate', 'activate', 'list', 'help']);
$app = App::create(dirname(__DIR__));
$staff = $app->container->get(StaffUser::class);
$db = $app->container->get(Database::class);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if (isset($options['help']) || $options === []) {
    echo "Usage:\n";
    echo "  php bin/create-staff.php --name=\"Full Name\" --email=you@example.com --role=admin|editor|sales|production\n";
    echo "  php bin/create-staff.php --reset-password --email=you@example.com\n";
    echo "  php bin/create-staff.php --deactivate|--activate --email=you@example.com\n";
    echo "  php bin/create-staff.php --list\n";
    exit(isset($options['help']) ? 0 : 1);
}

if (isset($options['list'])) {
    $rows = $db->fetchAll('SELECT id, name, email, role, is_active, last_login_at FROM staff_users ORDER BY id');
    if ($rows === []) {
        echo "No staff accounts yet.\n";
    }
    foreach ($rows as $row) {
        printf(
            "#%-3d %-24s %-32s %-11s %-9s last login: %s\n",
            $row['id'],
            $row['name'],
            $row['email'],
            $row['role'],
            $row['is_active'] ? 'active' : 'disabled',
            $row['last_login_at'] ?? 'never',
        );
    }
    exit(0);
}

$email = mb_strtolower(trim((string) ($options['email'] ?? '')));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $fail('Give a valid --email.');
}
$existing = $staff->findByEmail($email);

if (isset($options['deactivate']) || isset($options['activate'])) {
    $existing ?? $fail("No account for {$email}.");
    $active = isset($options['activate']);
    $db->update('staff_users', ['is_active' => $active ? 1 : 0], ['id' => $existing['id']]);
    if (!$active) {
        $app->container->get(TokenService::class)->revokeAll('staff', (int) $existing['id']);
    }
    echo $active ? "Activated {$email}.\n" : "Deactivated {$email} and ended its sessions.\n";
    exit(0);
}

$password = StaffUser::generatePassword();

if (isset($options['reset-password'])) {
    $existing ?? $fail("No account for {$email}.");
    $staff->updatePassword((int) $existing['id'], $password);
    $app->container->get(TokenService::class)->revokeAll('staff', (int) $existing['id']);
    echo "New password for {$email}: {$password}\n";
    echo "All sessions of this account were ended.\n";
    exit(0);
}

$name = trim((string) ($options['name'] ?? ''));
$role = (string) ($options['role'] ?? '');
if ($name === '') {
    $fail('Give the person\'s --name.');
}
if (!in_array($role, StaffUser::ROLES, true)) {
    $fail('Give a --role: ' . implode(', ', StaffUser::ROLES) . '.');
}
if ($existing !== null) {
    $fail("{$email} already has an account. Use --reset-password to give it a new password.");
}

$id = $staff->create($name, $email, $password, $role);
echo "Created {$role} account #{$id} for {$name} <{$email}>\n";
echo "Password: {$password}\n";
echo "Log in at /admin/login and change the password under Account.\n";
