<?php
// Prevent duplicate loading
if (defined('BOOTSTRAP_LOADED')) {
    return;
}
define('BOOTSTRAP_LOADED', true);

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Define base path
define('BASE_PATH', dirname(__DIR__));
define('APP_ROOT', '/Interactive-e-learning-system');

// Include required files in correct order
require_once BASE_PATH . '/config/database.php';

// Initialize Database first
try {
    $db = new Database();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Now include other files that depend on Database
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

// Initialize classes with Database instance
$auth = new Auth($db);
$functions = new Functions($db);
?>