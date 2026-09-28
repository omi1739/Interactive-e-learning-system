<?php
/**
 * create_admin.php - CLI-only tool to create the first administrator account.
 *
 * This replaces the old public web installer (setup.php), which could be
 * reached by anyone and could overwrite the database configuration.
 *
 * Usage (from the project root):
 *   php install/create_admin.php <email> <password> [first_name] [last_name]
 *
 * Example:
 *   php install/create_admin.php admin@school.edu 'Str0ng!Pass' Alex Admin
 *
 * The password is read with a leading '@' to avoid leaking it into the shell
 * history / process list:
 *   php install/create_admin.php admin@school.edu @mypassword
 *
 * This script refuses to run over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

define('BASE_PATH', dirname(__DIR__));

$local_config = BASE_PATH . '/config/local.php';
if (!is_file($local_config)) {
    exit("ERROR: config/local.php not found. Copy config/local.example.php and add your database credentials.\n");
}
require_once $local_config;
require_once BASE_PATH . '/config/database.php';

$argv_local = $_SERVER['argv'] ?? [];
array_shift($argv_local);

if (count($argv_local) < 2) {
    echo "Usage: php install/create_admin.php <email> <password> [first_name] [last_name]\n";
    echo "  Password may be prefixed with @ to read it from the argument as-is.\n\n";
    echo "Example:\n";
    echo "  php install/create_admin.php admin@school.edu 'Str0ng!Pass' Alex Admin\n";
    exit(1);
}

$email    = trim($argv_local[0]);
$password = $argv_local[1];
$first    = $argv_local[2] ?? 'Site';
$last     = $argv_local[3] ?? 'Administrator';

if (strpos($password, '@') === 0) {
    $password = substr($password, 1);
}
if ($password === '') {
    $password = rtrim(fgets(STDIN));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("ERROR: '$email' is not a valid email address.\n");
}
if (strlen($password) < 10) {
    exit("ERROR: password must be at least 10 characters long.\n");
}
if (empty(trim($first)) || empty(trim($last))) {
    exit("ERROR: first and last name must not be empty.\n");
}

try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    exit("ERROR: could not connect to the database. " . $e->getMessage() . "\n");
}

try {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        exit("ERROR: an account with the email '$email' already exists.\n");
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $conn->prepare(
        "INSERT INTO users (username, email, password_hash, first_name, last_name, role, is_active)
         VALUES (?, ?, ?, ?, ?, 'admin', 1)"
    );
    $username = strtolower(explode('@', $email)[0]);

    $insert->execute([$username, $email, $hash, trim($first), trim($last)]);

    echo "OK: administrator account created.\n";
    echo "    email:    {$email}\n";
    echo "    name:     " . trim($first) . " " . trim($last) . "\n";
    echo "\nNow sign in at /login.php and change the password from My Profile.\n";
} catch (PDOException $e) {
    exit("ERROR: database error. " . $e->getMessage() . "\n");
}
