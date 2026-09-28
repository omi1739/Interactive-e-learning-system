<?php
// Prevent duplicate loading
if (defined('BOOTSTRAP_LOADED')) {
    return;
}
define('BOOTSTRAP_LOADED', true);

// Define base filesystem path
define('BASE_PATH', dirname(__DIR__));

// Load local configuration first: it supplies the DB credentials and the
// deployment mode, and is gitignored so secrets never enter the repo.
$local_config = BASE_PATH . '/config/local.php';
if (!is_file($local_config)) {
    // Without credentials there is nothing to connect to. Show the site owner
    // exactly what to do, but do not create any public installer.
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Configuration Required</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card shadow-sm border-0 rounded-3">
                        <div class="card-body p-4">
                            <h3 class="card-title fw-bold mb-3">Configuration Required</h3>
                            <p class="text-muted">
                                The application is installed but not configured yet. This page is only
                                visible until the site owner finishes setup.
                            </p>
                            <p>Create the file <code>config/local.php</code> by copying
                                <code>config/local.example.php</code>, then enter your database
                                credentials and the cron token.</p>
                            <p class="mb-0 text-muted small">
                                Then import the schema and create the first admin account:
                            </p>
                            <pre class="bg-dark text-light p-3 rounded small mt-2 mb-0">mysql -u USER -p DATABASE &lt; database/schema-only.sql
php install/create_admin.php</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}
require_once $local_config;

// Deployment mode: 'production' (default) hides all PHP errors from visitors and
// logs them to logs/error.log. Displayed stack traces leak table names, file paths
// and SQL fragments, so production must never show them.
$app_env = getenv('APP_ENV') ?: (defined('APP_ENV_LOCAL') ? APP_ENV_LOCAL : 'production');
define('APP_ENV', $app_env);
define('APP_DEBUG', $app_env !== 'production');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// Writable directories. On GoogieHost these are configured to live OUTSIDE the
// web root (see config/local.example.php) so nothing can be fetched directly;
// they fall back to inside the project for local development only.
define('LOG_DIR',    defined('LOG_DIR_LOCAL')    ? LOG_DIR_LOCAL    : BASE_PATH . '/logs');
define('UPLOAD_DIR', defined('UPLOAD_DIR_LOCAL') ? UPLOAD_DIR_LOCAL : BASE_PATH . '/uploads/assignments');

ini_set('error_log', LOG_DIR . '/error.log');

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}

// Configure safe session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    
    // Only enforce secure cookies if actually running over HTTPS
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    ini_set('session.cookie_secure', $is_https ? 1 : 0);

    // Send the browser a hard "do not cache" header for authenticated pages.
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }
    
    session_start();
}

// Auto-detect APP_ROOT dynamically
if (!defined('APP_ROOT')) {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
    $basePath = realpath(BASE_PATH);

    if ($docRoot && $basePath && strpos($basePath, $docRoot) === 0) {
        $relPath = substr($basePath, strlen($docRoot));
        $relPath = str_replace('\\', '/', $relPath);
        $relPath = rtrim($relPath, '/');
        define('APP_ROOT', $relPath);
    } else {
        // Fallback for CLI or uncommon setups
        define('APP_ROOT', '');
    }
}

// Include required database configuration
require_once BASE_PATH . '/config/database.php';

// Initialize Database connection
try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    if (php_sapi_name() !== 'cli') {
        // Generic message only. The real PDO/DSN detail is already in the error log.
        http_response_code(503);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Service Unavailable</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="row justify-content-center">
                    <div class="col-md-7">
                        <div class="card shadow-sm border-0 rounded-3">
                            <div class="card-body p-4 text-center">
                                <h3 class="card-title fw-bold">Service Temporarily Unavailable</h3>
                                <p class="text-muted mb-0">
                                    The application could not reach its database. Please try again in a few minutes.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
    error_log("Bootstrap: " . $e->getMessage());
    exit(1);
}

// Now include classes that depend on Database
require_once BASE_PATH . '/includes/helpers.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

// Initialize classes
$auth = new Auth($db);
$functions = new Functions($db);
?>