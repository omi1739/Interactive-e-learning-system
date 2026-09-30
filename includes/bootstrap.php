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
        <style>
            /* Self-contained on purpose: this page renders before includes/helpers.php
               is loaded, so asset_url() does not exist yet, and an error page must stay
               readable even if the stylesheet fails to load. */
            :root { color-scheme: light; }
            * { box-sizing: border-box; }
            body { margin: 0; padding: 3rem 1rem; min-height: 100vh; display: flex;
                   align-items: center; justify-content: center;
                   font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                   background: #f5f6f8; color: #1f2430; line-height: 1.6; }
            .card { background: #fff; border-radius: 16px; padding: 2rem; max-width: 34rem;
                    width: 100%; box-shadow: 0 10px 30px rgba(16, 24, 40, .08);
                    border: 1px solid #e6e8ec; }
            h3 { margin: 0 0 1rem; font-size: 1.35rem; font-weight: 700; }
            p { margin: 0 0 1rem; }
            .muted { color: #6b7280; }
            .small { font-size: .85rem; }
            code { background: #eef0f3; padding: .1rem .35rem; border-radius: 5px;
                   font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                   font-size: .9em; }
            pre { background: #111827; color: #e5e7eb; padding: 1rem; border-radius: 10px;
                  overflow-x: auto; font-size: .85rem; margin: .5rem 0 0;
                  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        </style>
    </head>
    <body>
        <div class="card">
            <h3>Configuration Required</h3>
            <p class="muted">
                The application is installed but not configured yet. This page is only
                visible until the site owner finishes setup.
            </p>
            <p>Create the file <code>config/local.php</code> by copying
                <code>config/local.example.php</code>, then enter your database
                credentials and the cron token.</p>
            <p class="mb-0 muted small">
                Then import the schema and create the first admin account:
            </p>
            <pre>mysql -u USER -p DATABASE &lt; database/schema-only.sql
php install/create_admin.php</pre>
            <p class="muted small" style="margin-top:1rem">
                See <code>docs/DEPLOY.md</code> for the full checklist.
            </p>
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
            <style>
                /* Self-contained for the same reason as the page above: rendered before
                   includes/helpers.php exists, and a database outage is exactly when the
                   page has to stay legible. */
                * { box-sizing: border-box; }
                body { margin: 0; padding: 3rem 1rem; min-height: 100vh; display: flex;
                       align-items: center; justify-content: center;
                       font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                       background: #f5f6f8; color: #1f2430; line-height: 1.6; }
                .card { background: #fff; border-radius: 16px; padding: 2.5rem 2rem;
                        max-width: 30rem; width: 100%; text-align: center;
                        box-shadow: 0 10px 30px rgba(16, 24, 40, .08);
                        border: 1px solid #e6e8ec; }
                h3 { margin: 0 0 .75rem; font-size: 1.3rem; font-weight: 700; }
                p { margin: 0; color: #6b7280; }
            </style>
        </head>
        <body>
            <div class="card">
                <h3>Service Temporarily Unavailable</h3>
                <p>The application could not reach its database. Please try again in a few minutes.</p>
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