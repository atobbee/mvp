<?php
declare(strict_types=1);
// Usage: php bin/create-admin.php email password
require dirname(__DIR__) . '/src/bootstrap.php';

[, $email, $password] = $argv + [null, null, null];
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string)$password) < 12) {
    fwrite(STDERR, "Usage: php bin/create-admin.php email password (min 12 chars)\n");
    exit(1);
}
db()->insert('users', [
    'email' => strtolower($email), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'first_name' => 'Admin', 'last_name' => '', 'role' => 'admin', 'verification_status' => 'verified',
]);
echo "Admin created: $email\n";
