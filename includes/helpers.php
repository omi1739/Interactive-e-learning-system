<?php
/**
 * CSRF protection and output-escaping helpers.
 *
 * Every state-changing form must embed csrf_field() and every POST handler
 * must call verify_csrf() first, otherwise another site could make a logged-in
 * user's browser submit arbitrary requests.
 */

if (!function_exists('csrf_token')) {
    /**
     * Return the session's CSRF token, creating it on first use.
     */
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Hidden input to drop inside a <form>. Echo this directly.
     */
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * Validate the submitted token. Aborts the request on mismatch so the
     * handler that called it never runs.
     */
    function verify_csrf() {
        $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (empty($submitted) || !is_string($submitted)) {
            csrf_fail();
        }

        if (!hash_equals(csrf_token(), $submitted)) {
            csrf_fail();
        }

        return true;
    }
}

if (!function_exists('csrf_fail')) {
    /**
     * Stop processing and show a generic failure page. Never echo the tokens.
     */
    function csrf_fail() {
        if (!headers_sent()) {
            http_response_code(403);
        }
        if (php_sapi_name() === 'cli') {
            exit("ERROR: CSRF token verification failed.\n");
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Request Blocked</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="row justify-content-center">
                    <div class="col-md-7">
                        <div class="card shadow-sm border-0 rounded-3">
                            <div class="card-body p-4 text-center">
                                <h3 class="card-title fw-bold">Request Blocked</h3>
                                <p class="text-muted mb-0">
                                    Your form session expired or the request could not be verified.
                                    Please go back, reload the page and try again.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escape a value for output. Use this on every dynamic value that
     * reaches the page, or a stored XSS payload runs in a user's browser.
     */
    function e($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect_to')) {
    /**
     * Redirect to a path inside this application only.
     *
     * Guarding against open redirect matters: passing a user-controlled
     * $_GET value straight into redirect() lets an attacker bounce a
     * freshly authenticated user to a look-alike phishing page.
     */
    function redirect_to($path) {
        $path = (string) $path;

        // Reject absolute URLs, protocol-relative URLs and backslash tricks.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || strpos($path, '//') === 0) {
            $path = 'dashboard.php';
        }
        if (strpos($path, "\n") !== false || strpos($path, "\r") !== false) {
            $path = 'dashboard.php';
        }

        if (!headers_sent()) {
            header('Location: ' . $path);
            exit;
        }
    }
}

if (!function_exists('mysql_datetime')) {
    /**
     * Normalize a value for a MySQL DATETIME column.
     *
     * <input type="datetime-local"> submits "2026-01-31T14:30", but MySQL only
     * accepts "2026-01-31 14:30:00". Passing the raw value through causes a
     * truncation error, so the T is converted and a missing time defaulted.
     * Returns null for empty input so the caller can store NULL.
     */
    function mysql_datetime($value, $default_time = '00:00:00') {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // "2026-01-31T14:30" and "2026-01-31T14:30:05"
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return $m[1] . ' ' . $m[2] . ':' . (isset($m[3]) ? $m[3] : '00');
        }

        // Date only
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' ' . $default_time;
        }

        // Already a normal datetime
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return strlen($value) === 16 ? $value . ':00' : $value;
        }

        return null;
    }
}

if (!function_exists('default_allowed_extensions')) {
    /**
     * Fallback extension allowlist for assignments that do not define one.
     * Deliberately excludes anything the web server might execute, plus
     * html/svg/js which could run script in a viewer's browser.
     */
    function default_allowed_extensions() {
        return [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
            'txt', 'rtf', 'csv', 'md', 'zip',
            'png', 'jpg', 'jpeg', 'gif', 'webp',
            // Source files. Scripting languages are deliberately absent: 'py'
            // is on the executable blocklist, so listing it as allowed would be
            // self-contradictory (and the double-extension check rejects any
            // filename ending in .py regardless).
            'c', 'cpp', 'h', 'hpp', 'cs', 'java', 'rb', 'go', 'rs',
            'sql', 'ipynb',
        ];
    }
}

if (!function_exists('allowed_upload_extensions')) {
    /**
     * Resolve the extension allowlist for an assignment.
     *
     * Always returns a non-empty list: the previous code treated an empty
     * allowed_file_types as "allow everything", which let a .php upload
     * through on any assignment that had not configured its types.
     */
    function allowed_upload_extensions($configured) {
        $configured = trim((string) $configured);
        if ($configured === '') {
            return default_allowed_extensions();
        }

        $exts = [];
        foreach (explode(',', $configured) as $ext) {
            $ext = strtolower(trim($ext, " \t\n\r\0\x0B."));
            if ($ext === '') {
                continue;
            }
            // Refuse executable types even if an instructor lists them.
            if (preg_match('/^(php|phtml|phar|php[0-9]|cgi|pl|py|sh|exe|htaccess)/', $ext)) {
                continue;
            }
            $exts[] = $ext;
        }

        return $exts ? $exts : default_allowed_extensions();
    }
}

if (!function_exists('allowed_upload_mimes')) {
    /**
     * Content types accepted by the finfo check.
     */
    function allowed_upload_mimes() {
        return [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/rtf',
            'text/plain',
            'text/csv',
            'text/markdown',
            'text/html',
            'application/json',
            'text/x-c',
            'text/x-c++',
            'text/x-java',
            'text/x-python',
            'text/x-ruby',
            'text/x-go',
            'text/x-rust',
            'text/x-sql',
            'application/zip',
            'application/x-zip-compressed',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            // Detected for many source files
            'text/x-tex', 'application/octet-stream',
        ];
    }
}

if (!function_exists('upload_detected_mime')) {
    /**
     * Best-effort content type of an uploaded file, or null if unknown.
     */
    function upload_detected_mime($tmp_path) {
        if (!function_exists('finfo_open')) {
            // Without ext/fileinfo we cannot verify content, so refuse.
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $tmp_path);
        finfo_close($finfo);
        return $mime ?: null;
    }
}
