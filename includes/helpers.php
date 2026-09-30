<?php
/**
 * Shared helpers: security, URL building, formatting and UI fragments.
 *
 * Two concerns live here deliberately:
 *
 *  - CSRF protection and output escaping, because every state-changing form
 *    must embed csrf_field() and every POST handler must call verify_csrf()
 *    first, otherwise another site could make a logged-in user's browser submit
 *    arbitrary requests.
 *
 *  - Presentation helpers (ui_*, flash_*, asset_url()) so pages stay free of
 *    duplicated markup and status colour decisions live in one place.
 */

/* ==========================================================================
   mbstring compatibility
   --------------------------------------------------------------------------
   Name handling needs to be Unicode-aware to avoid cutting a multi-byte
   character in half. mbstring is not enabled on every host, and calling an
   undefined function is a fatal error, so fall back to the byte-based
   equivalents. Documented as a requirement in docs/DEPLOY.md, but the app must
   not die without it.
   ========================================================================== */

if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $encoding = null) { return strlen($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $encoding = null) {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s, $encoding = null) { return strtoupper($s); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $encoding = null) { return strtolower($s); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($h, $needle, $offset = 0, $encoding = null) { return strpos($h, $needle, $offset); }
}
if (!function_exists('mb_strrpos')) {
    function mb_strrpos($h, $needle, $offset = 0, $encoding = null) { return strrpos($h, $needle, $offset); }
}

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
            <link href="<?php echo e_attr(asset_url('vendor/bootstrap/bootstrap.min.css')); ?>" rel="stylesheet">
            <link href="<?php echo e_attr(asset_url('css/app.css')); ?>" rel="stylesheet">
        </head>
        <body>
            <div class="d-flex align-items-center justify-content-center" style="min-height:100vh;padding:2rem 1rem">
                <div class="surface text-center" style="max-width:28rem;width:100%">
                    <div class="surface__body" style="padding:2rem">
                        <div class="empty-state__icon mx-auto mb-3" style="background:#fee2e2;color:#b91c1c">
                            <i class="fas fa-shield-halved" aria-hidden="true"></i>
                        </div>
                        <h1 class="h4 mb-2">Request Blocked</h1>
                        <p class="text-muted mb-3">
                            This request could not be verified. It usually means the form sat open
                            too long or the page was reloaded. Please go back, reload and try again.
                        </p>
                        <a class="btn btn-primary" href="javascript:history.back()">
                            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Go back
                        </a>
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

if (!function_exists('e_attr')) {
    /**
     * Escape for use inside an HTML attribute value.
     *
     * Identical to e() in modern PHP, but kept separate so a reader can see at
     * a glance that a value is landing in an attribute rather than text.
     */
    function e_attr($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('e_js')) {
    /**
     * Escape a value for embedding inside a <script> block or a JS string.
     *
     * json_encode alone is not enough: it does not escape "</script>", so a
     * value containing that sequence would end the script element early.
     */
    function e_js($value) {
        $json = json_encode((string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        return $json === false ? '""' : $json;
    }
}

/* ==========================================================================
   URLs and assets
   ========================================================================== */

if (!function_exists('app_root')) {
    /**
     * The application's URL prefix, normalised to be '' or '/prefix'.
     */
    function app_root() {
        if (!defined('APP_ROOT') || APP_ROOT === '' || APP_ROOT === '/') {
            return '';
        }
        return '/' . trim(APP_ROOT, '/');
    }
}

if (!function_exists('app_url')) {
    /**
     * Build a URL that is correct from any directory in the project.
     *
     * Pages live at three different depths (root, student/, instructor/), so a
     * hand-written relative href like "courses.php" silently breaks when a page
     * is moved or when APP_ROOT is not ''. Always build links with this.
     *
     *     app_url('student/dashboard.php')
     *     app_url('download.php', ['file' => $id])
     */
    function app_url($path = '', array $query = []) {
        $path = ltrim((string) $path, '/');
        $url = app_root() . ($path === '' ? '/' : '/' . $path);

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}

if (!function_exists('asset_url')) {
    /**
     * URL for a file in assets/, cache-busted by its modification time.
     *
     * app.css and app.js are served with a one-year max-age, so a browser that
     * already has the old copy would never refetch it. Appending ?v=<mtime>
     * gives each deployed version its own URL and busts the cache for free.
     */
    function asset_url($path) {
        $path = ltrim((string) $path, '/');
        $absolute = (defined('BASE_PATH') ? BASE_PATH : '') . '/assets/' . $path;
        $version = @filemtime($absolute);
        $url = app_url('assets/' . $path);

        return $version ? $url . '?v=' . $version : $url;
    }
}

/* ==========================================================================
   Flash messages
   --------------------------------------------------------------------------
   Handlers set a message and redirect; the next request renders it. This
   survives the PRG pattern, so a browser refresh never re-submits a form.
   ========================================================================== */

if (!function_exists('flash_set')) {
    function flash_set($type, $message) {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        // Several handlers report more than one problem on the same request.
        $_SESSION['flash'][] = ['type' => (string) $type, 'message' => (string) $message];
    }
}

if (!function_exists('flash_success')) {
    function flash_success($message) { flash_set('success', $message); }
}

if (!function_exists('flash_error')) {
    function flash_error($message) { flash_set('danger', $message); }
}

if (!function_exists('flash_warning')) {
    function flash_warning($message) { flash_set('warning', $message); }
}

if (!function_exists('flash_info')) {
    function flash_info($message) { flash_set('info', $message); }
}

if (!function_exists('flash_take')) {
    /**
     * Read and clear the queued messages. Call once per request, from the
     * layout, so a second render does not duplicate them.
     */
    function flash_take() {
        if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            return [];
        }
        $messages = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $messages;
    }
}

if (!function_exists('flash_render')) {
    /**
     * Render the queued messages as dismissible alerts. Echo directly.
     */
    function flash_render() {
        $messages = flash_take();
        if (!$messages) {
            return;
        }

        $icons = [
            'success' => 'fa-circle-check',
            'danger'  => 'fa-circle-exclamation',
            'warning' => 'fa-triangle-exclamation',
            'info'    => 'fa-circle-info',
        ];
        $heads = [
            'success' => 'Done',
            'danger'  => 'Something went wrong',
            'warning' => 'Heads up',
            'info'    => 'Note',
        ];

        echo '<div class="stack-sm mb-3" role="status" aria-live="polite">';
        foreach ($messages as $msg) {
            $type = isset($icons[$msg['type']]) ? $msg['type'] : 'info';
            // Errors stay on screen; confirmations fade.
            $autodismiss = ($type === 'success' || $type === 'info')
                ? ' data-autodismiss="9000"'
                : '';
            echo '<div class="alert alert-' . e_attr($type) . ' alert-dismissible fade show" role="alert"' . $autodismiss . '>';
            echo '<div class="alert-heading"><i class="fas ' . e_attr($icons[$type]) . '" aria-hidden="true"></i>'
                . e($heads[$type]) . '</div>';
            echo '<div class="break-words-anywhere">' . e($msg['message']) . '</div>';
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo '</div>';
        }
        echo '</div>';
    }
}

/* ==========================================================================
   Ownership checks
   --------------------------------------------------------------------------
   Instructor pages act on rows reached through a URL parameter, so every
   write has to re-establish that the target belongs to the signed-in
   instructor. These three helpers centralise that so a page cannot forget the
   join, and they return the row so the caller does not query it a second time.
   ========================================================================== */

if (!function_exists('instructor_owns_course')) {
    /**
     * @return array|null The course row when $instructor_id owns it, else null.
     */
    function instructor_owns_course($conn, $course_id, $instructor_id)
    {
        $course_id = (int) $course_id;
        $instructor_id = (int) $instructor_id;
        if ($course_id <= 0 || $instructor_id <= 0) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT * FROM courses WHERE course_id = ? AND instructor_id = ? LIMIT 1'
        );
        $stmt->execute([$course_id, $instructor_id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('instructor_owns_module')) {
    /**
     * @return array|null The module row (with course_id) when owned, else null.
     */
    function instructor_owns_module($conn, $module_id, $instructor_id)
    {
        $module_id = (int) $module_id;
        $instructor_id = (int) $instructor_id;
        if ($module_id <= 0 || $instructor_id <= 0) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT m.* FROM modules m
               JOIN courses c ON c.course_id = m.course_id
             WHERE m.module_id = ? AND c.instructor_id = ?
             LIMIT 1'
        );
        $stmt->execute([$module_id, $instructor_id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('instructor_owns_assignment')) {
    /**
     * The assignment plus the course and module context pages need for titles
     * and breadcrumbs.
     *
     * @return array|null Null when the assignment does not exist or is not
     *                    owned by $instructor_id.
     */
    function instructor_owns_assignment($conn, $assignment_id, $instructor_id)
    {
        $assignment_id = (int) $assignment_id;
        $instructor_id = (int) $instructor_id;
        if ($assignment_id <= 0 || $instructor_id <= 0) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT a.*, m.title AS module_title, m.course_id, c.title AS course_title, c.course_code
               FROM assignments a
               JOIN modules m ON m.module_id = a.module_id
               JOIN courses c ON c.course_id = m.course_id
             WHERE a.assignment_id = ? AND c.instructor_id = ?
             LIMIT 1'
        );
        $stmt->execute([$assignment_id, $instructor_id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('require_assignment_ownership')) {
    /**
     * Stop the request when the signed-in instructor does not own the
     * assignment. Redirects on failure so the visitor gets a usable page back.
     */
    function require_assignment_ownership($conn, $assignment_id, $instructor_id, $fallback = 'assignments.php')
    {
        $assignment = instructor_owns_assignment($conn, $assignment_id, $instructor_id);
        if (!$assignment) {
            flash_error('That assignment was not found, or it belongs to another instructor.');
            redirect_to(app_url($fallback));
        }
        return $assignment;
    }
}

if (!function_exists('require_course_ownership')) {
    /**
     * Stop the request when the signed-in instructor does not own the course.
     */
    function require_course_ownership($conn, $course_id, $instructor_id, $fallback = 'courses.php')
    {
        $course = instructor_owns_course($conn, $course_id, $instructor_id);
        if (!$course) {
            flash_error('That course was not found, or it belongs to another instructor.');
            redirect_to(app_url($fallback));
        }
        return $course;
    }
}

if (!function_exists('require_module_ownership')) {
    /**
     * Stop the request when the signed-in instructor does not own the module.
     */
    function require_module_ownership($conn, $module_id, $instructor_id, $fallback = 'courses.php')
    {
        $module = instructor_owns_module($conn, $module_id, $instructor_id);
        if (!$module) {
            flash_error('That module was not found, or it belongs to another instructor.');
            redirect_to(app_url($fallback));
        }
        return $module;
    }
}

/* ==========================================================================
   Request input helpers
   ========================================================================== */

if (!function_exists('param_int')) {
    /**
     * Read an integer from $_GET or $_POST. Returns $default when the value is
     * absent or not a clean integer, so a caller never has to guess whether
     * intval() got something meaningful.
     */
    function param_int($key, $default = 0, $source = null)
    {
        $bag = $source ?? $_REQUEST;
        if (!isset($bag[$key]) && !array_key_exists($key, $bag)) {
            return $default;
        }
        $raw = $bag[$key];
        if (is_array($raw) || !preg_match('/^-?\d+$/', (string) $raw)) {
            return $default;
        }
        return (int) $raw;
    }
}

if (!function_exists('param_str')) {
    /**
     * Read a trimmed string from $_GET or $_POST.
     */
    function param_str($key, $default = '', $source = null)
    {
        $bag = $source ?? $_REQUEST;
        if (!isset($bag[$key]) || is_array($bag[$key])) {
            return $default;
        }
        $value = trim((string) $bag[$key]);
        return $value === '' ? $default : $value;
    }
}

if (!function_exists('param_enum')) {
    /**
     * Read a value that must be one of a fixed set. Anything else falls back to
     * $default, which is how enum-ish columns stay out of the WHERE clause
     * unvalidated.
     */
    function param_enum($key, array $allowed, $default, $source = null)
    {
        $value = param_str($key, '', $source);
        return in_array($value, $allowed, true) ? $value : $default;
    }
}

if (!function_exists('is_post')) {
    /**
     * True for a POST request. Prefer this over `if ($_POST)`, which is also
     * true for a form whose only submitted field is falsy.
     */
    function is_post()
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

/* ==========================================================================
   Peer review identity
   --------------------------------------------------------------------------
   Peer review is double blind. Two different identities are involved and each
   is hidden from a different audience, so the rule lives here rather than
   being re-invented on every page:

     - the submission AUTHOR is hidden from REVIEWERS, otherwise a reviewer
       knows whose work they are judging and the review is not blind;
     - the REVIEWER is hidden from the AUTHOR when the review row has
       is_anonymous = 1.

   The author and any instructor/admin always see real names, otherwise nobody
   could moderate a disputed review.
   ========================================================================== */

if (!function_exists('review_author_label')) {
    /**
     * How a submission's author should be named for the current viewer.
     *
     * @param array  $submission  Row with student_id (or author_id) and, when
     *                             known, first_name / last_name.
     * @param int    $viewerId    The signed-in user id.
     * @param string $viewerRole  The signed-in user's role.
     * @return string             Display name, already safe to escape.
     */
    function review_author_label(array $submission, $viewerId, $viewerRole = 'student')
    {
        $authorId = $submission['author_id'] ?? $submission['student_id'] ?? null;
        $authorId = $authorId === null ? null : (int) $authorId;
        $viewerId = (int) $viewerId;

        // Instructors and admins moderate, so they always see the real name.
        $isPrivileged = in_array($viewerRole, ['instructor', 'admin'], true);
        $isSelf = $authorId !== null && $authorId === $viewerId;

        if ($isPrivileged || $isSelf) {
            $name = trim(($submission['first_name'] ?? '') . ' ' . ($submission['last_name'] ?? ''));
            return $name !== '' ? $name : 'Unknown student';
        }

        return 'Anonymous classmate';
    }
}

if (!function_exists('review_author_is_named')) {
    /**
     * True when the current viewer is allowed to see the author's real name.
     * Use this to decide whether to render an avatar or the anonymous glyph.
     */
    function review_author_is_named(array $submission, $viewerId, $viewerRole = 'student')
    {
        return review_author_label($submission, $viewerId, $viewerRole) !== 'Anonymous classmate';
    }
}

if (!function_exists('review_reviewer_label')) {
    /**
     * How a review's author (the reviewer) should be named when the reviewer
     * is looking at it. The reviewer always sees their own name.
     */
    function review_reviewer_label(array $review, $viewerId, $viewerRole = 'student')
    {
        $reviewerId = $review['reviewer_id'] ?? null;
        $reviewerId = $reviewerId === null ? null : (int) $reviewerId;

        if (in_array($viewerRole, ['instructor', 'admin'], true)
            || ($reviewerId !== null && $reviewerId === (int) $viewerId)) {
            $name = trim(
                ($review['reviewer_first_name'] ?? $review['first_name'] ?? '') . ' '
                . ($review['reviewer_last_name'] ?? $review['last_name'] ?? '')
            );
            return $name !== '' ? $name : 'Reviewer';
        }

        // Anonymous reviews are attributed to the submission author.
        return empty($review['is_anonymous']) ? 'Reviewer' : 'Anonymous peer';
    }
}

/* ==========================================================================
   Formatting
   ========================================================================== */

if (!function_exists('ui_initials')) {
    /**
     * Up to two uppercase initials for an avatar.
     */
    function ui_initials($first, $last = '') {
        $first = trim((string) $first);
        $last = trim((string) $last);

        $a = $first !== '' ? mb_strtoupper(mb_substr($first, 0, 1)) : '';
        $b = $last !== '' ? mb_strtoupper(mb_substr($last, 0, 1)) : '';

        if ($a === '' && $b === '') {
            return '?';
        }
        return $a . $b;
    }
}

if (!function_exists('ui_avatar')) {
    /**
     * An initial-based avatar. Echo directly.
     */
    function ui_avatar($first, $last = '', $size = '') {
        $class = 'avatar' . ($size !== '' ? ' avatar--' . e_attr($size) : '');
        return '<span class="' . $class . '" aria-hidden="true">' . e(ui_initials($first, $last)) . '</span>';
    }
}

if (!function_exists('ui_user_name')) {
    /**
     * Best available display name, falling back to the username.
     */
    function ui_user_name($row, $key = null) {
        $first = $row['first_name'] ?? '';
        $last = $row['last_name'] ?? '';

        if ($key !== null && isset($row[$key])) {
            return (string) $row[$key];
        }

        $name = trim($first . ' ' . $last);
        return $name !== '' ? $name : (string) ($row['username'] ?? 'Unknown user');
    }
}

if (!function_exists('ui_datetime')) {
    /**
     * Render a MySQL DATETIME, or a friendly placeholder when it is NULL.
     */
    function ui_datetime($value, $format = 'M j, Y g:i A', $empty = 'Not set') {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return $empty;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return $empty;
        }
        return date($format, $ts);
    }
}

if (!function_exists('ui_date')) {
    function ui_date($value, $format = 'M j, Y', $empty = 'Not set') {
        return ui_datetime($value, $format, $empty);
    }
}

if (!function_exists('ui_ago')) {
    /**
     * "3 days ago" style timestamp, falling back to an absolute date once the
     * value is old enough that a relative label stops being useful.
     */
    function ui_ago($value, $empty = 'Never') {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return $empty;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return $empty;
        }

        $diff = time() - $ts;
        if ($diff < 0) {
            return date('M j, Y', $ts);
        }
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 7 * 86400) {
            $d = (int) floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        return date('M j, Y', $ts);
    }
}

if (!function_exists('ui_percent')) {
    /**
     * Percentage of earned against possible points, clamped to 0-100.
     *
     * Grades in this schema are stored as points, not percentages, so a raw
     * final_grade of 45 out of 60 must be shown as 75%.
     */
    function ui_percent($earned, $possible) {
        $earned = (float) $earned;
        $possible = (float) $possible;

        if ($possible <= 0) {
            return null;
        }
        $pct = ($earned / $possible) * 100;
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 100) {
            $pct = 100;
        }
        return round($pct, 1);
    }
}

if (!function_exists('ui_grade_tone')) {
    /**
     * Tone for a percentage, used to colour the grade badge and progress bar.
     */
    function ui_grade_tone($pct) {
        if ($pct === null) {
            return 'neutral';
        }
        if ($pct >= 90) {
            return 'success';
        }
        if ($pct >= 75) {
            return 'primary';
        }
        if ($pct >= 60) {
            return 'warning';
        }
        return 'danger';
    }
}

if (!function_exists('ui_grade_badge')) {
    /**
     * Grade badge showing the percentage, or a dash when not yet graded.
     */
    function ui_grade_badge($earned, $possible, $earnedLabel = null, $possibleLabel = null) {
        if ($earned === null || $earnedLabel === null) {
            return '<span class="badge badge-soft-neutral">Not graded</span>';
        }

        $pct = ui_percent($earned, $possible);
        $tone = ui_grade_tone($pct);

        $text = $pct !== null ? $pct . '%' : (string) $earnedLabel;
        if ($earnedLabel !== null) {
            $text = e($text) . ' <span class="fw-normal opacity-75">(' . e($earnedLabel)
                . ($possibleLabel !== null ? ' / ' . e($possibleLabel) : '') . ')</span>';
        }

        return '<span class="badge badge-soft-' . $tone . '">' . $text . '</span>';
    }
}

if (!function_exists('mysql_datetime_input')) {
    /**
     * Render a stored MySQL datetime as the value an <input type="datetime-local">
     * expects ("2026-01-31T14:30"), or an empty string when there is nothing to
     * show. This is the inverse of mysql_datetime().
     */
    function mysql_datetime_input($value, $format = 'Y-m-d\TH:i')
    {
        if (empty($value)) {
            return '';
        }

        $ts = strtotime((string) $value);
        if ($ts === false) {
            return '';
        }

        return date($format, $ts);
    }
}

if (!function_exists('student_enrolled_in_course')) {
    /**
     * Is this student actively enrolled in this course?
     */
    function student_enrolled_in_course(PDO $conn, int $course_id, int $user_id): bool
    {
        if ($course_id <= 0 || $user_id <= 0) {
            return false;
        }

        $stmt = $conn->prepare("SELECT 1 FROM enrollments
                                WHERE course_id = ? AND user_id = ? AND enrollment_status = 'approved'
                                LIMIT 1");
        $stmt->execute([$course_id, $user_id]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('course_lesson_ids')) {
    /**
     * Every published lesson id in a course, in module then lesson order.
     *
     * Used to check that a lesson a student is marking complete really belongs
     * to the course they are enrolled in, rather than trusting a posted id.
     */
    function course_lesson_ids(PDO $conn, int $course_id): array
    {
        $stmt = $conn->prepare("SELECT l.lesson_id
                                FROM lessons l
                                JOIN modules m ON l.module_id = m.module_id
                                WHERE m.course_id = ? AND l.is_published = TRUE AND m.is_published = TRUE
                                ORDER BY m.module_order, l.lesson_order");
        $stmt->execute([$course_id]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('set_lesson_completion')) {
    /**
     * Mark a lesson complete or incomplete for a student.
     *
     * One row per pair is kept either way: un-marking flips is_completed back
     * to 0 and clears completed_at rather than deleting, so the record of when
     * the work was finished survives. ON DUPLICATE KEY UPDATE leans on the
     * unique_user_lesson index, so a double submit cannot create duplicates.
     */
    function set_lesson_completion(PDO $conn, int $user_id, int $lesson_id, bool $completed): bool
    {
        if ($user_id <= 0 || $lesson_id <= 0) {
            return false;
        }

        $stmt = $conn->prepare("INSERT INTO lesson_progress (user_id, lesson_id, is_completed, completed_at)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    is_completed = VALUES(is_completed),
                                    completed_at = VALUES(completed_at)");
        $flag = $completed ? 1 : 0;
        $now = $completed ? date('Y-m-d H:i:s') : null;

        return $stmt->execute([$user_id, $lesson_id, $flag, $now]);
    }
}

if (!function_exists('completed_lesson_ids')) {
    /**
     * Which of the given lessons this student has finished.
     *
     * @param int[] $lesson_ids
     * @return array<int, true> keyed by lesson id
     */
    function completed_lesson_ids(PDO $conn, int $user_id, array $lesson_ids): array
    {
        if (empty($lesson_ids) || $user_id <= 0) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($lesson_ids), '?'));
        $stmt = $conn->prepare("SELECT lesson_id FROM lesson_progress
                                WHERE user_id = ? AND is_completed = 1 AND lesson_id IN ($ph)");
        $stmt->execute(array_merge([$user_id], $lesson_ids));

        $done = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $done[(int)$id] = true;
        }

        return $done;
    }
}

if (!function_exists('ui_progress_meter')) {
    /**
     * A labelled progress bar, or an honest "not started" note when there is
     * nothing to measure. Callers used to render fixed percentages here.
     */
    function ui_progress_meter($done, $total, $label = null, $id = 'progress')
    {
        $done = (int)$done;
        $total = (int)$total;

        if ($total <= 0) {
            return '<p class="text-muted mb-0"><i class="fas fa-minus me-1" aria-hidden="true"></i>'
                . 'Nothing to track yet.</p>';
        }

        $pct = (int)round(($done / $total) * 100);
        $pct = max(0, min(100, $pct));
        $label = $label !== null ? $label : $done . ' of ' . $total;

        return '<div class="progress mb-2" role="progressbar" aria-labelledby="' . e_attr($id) . '-label"'
            . ' aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
            . '<div class="progress-bar" style="width: ' . $pct . '%">' . $pct . '%</div>'
            . '</div>'
            . '<p class="mb-0" id="' . e_attr($id) . '-label">' . e($label) . '</p>';
    }
}

if (!function_exists('valid_extension_list')) {
    /**
     * Is this a well-formed comma-separated extension list?
     *
     * The column is consulted with LIKE when validating an upload, so it has to
     * stay a plain list of extensions. An empty string is valid and means "use
     * the configured defaults".
     */
    function valid_extension_list($csv): bool
    {
        $csv = trim((string) $csv);
        if ($csv === '') {
            return true;
        }

        foreach (explode(',', $csv) as $ext) {
            $ext = trim($ext);
            if ($ext === '' || !preg_match('/^[a-z0-9]{1,10}$/i', $ext)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('ui_accept_list')) {
    /**
     * Build the value for an <input type="file" accept="..."> attribute.
     *
     * The column stores a bare comma list like "pdf,doc,docx", but the accept
     * attribute needs dot-prefixed extensions or the browser silently ignores
     * the filter. This reuses allowed_upload_extensions() rather than
     * re-parsing, so the picker can never offer a type the upload check would
     * reject (notably executable ones).
     */
    function ui_accept_list($csv, $default = null)
    {
        $exts = allowed_upload_extensions($csv);

        if (empty($exts)) {
            $exts = $default !== null ? $default : allowed_upload_extensions('');
        }

        return '.' . implode(',.', array_map('strtolower', $exts));
    }
}

if (!function_exists('ui_is_late')) {
    /**
     * Was something submitted after its deadline?
     *
     * Accepts empty or unparseable dates and answers false for them, because a
     * submission with no deadline is never late and a malformed date must not
     * silently brand a student's work "late".
     */
    function ui_is_late($submitted_at, $due_at): bool
    {
        if (empty($submitted_at) || empty($due_at)) {
            return false;
        }

        $submitted = strtotime((string) $submitted_at);
        $due = strtotime((string) $due_at);

        if ($submitted === false || $due === false) {
            return false;
        }

        return $submitted > $due;
    }
}

if (!function_exists('ui_num')) {
    /**
     * Format a number for display: integers stay integers, fractions keep up
     * to two decimals, and anything above 1,000 gets a thousands separator.
     *
     *     ui_num(0.5)   => '0.5'
     *     ui_num(10)    => '10'
     *     ui_num(1234.5)=> '1,234.5'
     */
    function ui_num($value, $decimals = 2) {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '-';
        }

        $value = (float) $value;
        // Drop a trailing ".00" so a whole score does not read as "10.00".
        $rounded = round($value, $decimals);
        if (abs($rounded - round($rounded)) < 0.0000001) {
            $rounded = round($rounded);
        }

        return number_format($rounded, (abs($rounded - round($rounded)) < 0.0000001) ? 0 : $decimals);
    }
}

if (!function_exists('ui_file_size')) {
    function ui_file_size($bytes, $empty = '-') {
        if ($bytes === null || $bytes === '' || $bytes <= 0) {
            return $empty;
        }
        $bytes = (float) $bytes;
        if ($bytes < 1024) {
            return round($bytes) . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

if (!function_exists('ui_extension')) {
    /**
     * Lowercase extension of a file name, without the dot.
     */
    function ui_extension($filename) {
        $filename = (string) $filename;
        $pos = strrpos($filename, '.');
        if ($pos === false || $pos === strlen($filename) - 1) {
            return '';
        }
        return strtolower(substr($filename, $pos + 1));
    }
}

if (!function_exists('ui_file_icon')) {
    /**
     * Font Awesome icon name that matches a file extension.
     */
    function ui_file_icon($filename) {
        $ext = ui_extension($filename);

        $map = [
            'pdf'  => 'fa-file-pdf',
            'doc'  => 'fa-file-word',
            'docx' => 'fa-file-word',
            'odt'  => 'fa-file-word',
            'rtf'  => 'fa-file-word',
            'txt'  => 'fa-file-lines',
            'md'   => 'fa-file-lines',
            'csv'  => 'fa-file-csv',
            'xls'  => 'fa-file-excel',
            'xlsx' => 'fa-file-excel',
            'ods'  => 'fa-file-excel',
            'ppt'  => 'fa-file-powerpoint',
            'pptx' => 'fa-file-powerpoint',
            'zip'  => 'fa-file-zipper',
            'png'  => 'fa-file-image',
            'jpg'  => 'fa-file-image',
            'jpeg' => 'fa-file-image',
            'gif'  => 'fa-file-image',
            'webp' => 'fa-file-image',
            'c'    => 'fa-file-code',
            'cpp'  => 'fa-file-code',
            'h'    => 'fa-file-code',
            'hpp'  => 'fa-file-code',
            'cs'   => 'fa-file-code',
            'java' => 'fa-file-code',
            'py'   => 'fa-file-code',
            'js'   => 'fa-file-code',
            'php'  => 'fa-file-code',
            'sql'  => 'fa-file-code',
            'ipynb' => 'fa-file-code',
        ];

        return isset($map[$ext]) ? $map[$ext] : 'fa-file';
    }
}

if (!function_exists('ui_truncate')) {
    /**
     * Shorten a string without cutting a word in half.
     */
    function ui_truncate($text, $length = 90, $suffix = '...') {
        $text = trim((string) $text);
        if ($text === '' || mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut) . $suffix;
    }
}

/* ==========================================================================
   UI fragments
   ========================================================================== */

if (!function_exists('ui_badge')) {
    /**
     * Soft status pill. Echo directly.
     */
    function ui_badge($text, $tone = 'neutral', $icon = '') {
        $iconHtml = $icon !== ''
            ? '<i class="fas ' . e_attr($icon) . '" aria-hidden="true"></i>'
            : '';
        return '<span class="badge badge-soft-' . e_attr($tone) . '">' . $iconHtml . e($text) . '</span>';
    }
}

if (!function_exists('ui_status_badge')) {
    /**
     * Map a status string from the database to a tone + friendly label.
     *
     * Covers enrollment_status, submission status, review status and
     * enrollment-style values, so no page invents its own colour scheme.
     */
    function ui_status_badge($status, $emptyLabel = 'Unknown') {
        $status = strtolower(trim((string) $status));

        $map = [
            // enrollments
            'approved'  => ['Approved', 'success', 'fa-circle-check'],
            'pending'   => ['Pending', 'warning', 'fa-clock'],
            'rejected'  => ['Rejected', 'danger', 'fa-circle-xmark'],
            'dropped'   => ['Dropped', 'neutral', 'fa-circle-minus'],
            // submissions
            'submitted' => ['Submitted', 'primary', 'fa-upload'],
            'resubmitted' => ['Resubmitted', 'primary', 'fa-upload'],
            'draft'     => ['Draft', 'neutral', 'fa-pen'],
            'graded'    => ['Graded', 'success', 'fa-star'],
            'in_progress' => ['In progress', 'warning', 'fa-spinner'],
            'not_started' => ['Not started', 'neutral', 'fa-circle'],
            'completed' => ['Completed', 'success', 'fa-circle-check'],
            // Review-adjacent vocabulary. peer_reviews.status is only
            // ENUM('in_progress','completed') today; the rest are labels for
            // review-request states that other schemas use, kept here so the
            // map stays in one place if they are introduced.
            'accepted'  => ['Accepted', 'info', 'fa-inbox'],
            'declined'  => ['Declined', 'neutral', 'fa-inbox'],
            'overdue'   => ['Overdue', 'danger', 'fa-triangle-exclamation'],
        ];

        if (isset($map[$status])) {
            list($label, $tone, $icon) = $map[$status];
            return ui_badge($label, $tone, $icon);
        }

            // Unknown status: show it as-is so a new enum value is visible rather
            // than silently blank.
            $label = ucfirst(str_replace('_', ' ', (string) $status));
            return ui_badge($label === '' ? $emptyLabel : $label, 'neutral');
    }
}

if (!function_exists('ui_due_badge')) {
    /**
     * Deadline pill that also communicates urgency, not just the date.
     */
    function ui_due_badge($dueDate, $submitted = false) {
        if (empty($dueDate)) {
            return ui_badge('No deadline', 'neutral', 'fa-infinity');
        }

        $ts = strtotime((string) $dueDate);
        if ($ts === false) {
            return ui_badge(ui_date($dueDate), 'neutral', 'fa-calendar');
        }

        $label = date('M j', $ts) . ', ' . date('g:i A', $ts);
        $days = (int) floor(($ts - time()) / 86400);

        if ($submitted) {
            return ui_badge('Submitted', 'success', 'fa-circle-check');
        }
        if ($days < 0) {
            $over = abs($days);
            return ui_badge('Overdue by ' . $over . ' day' . ($over === 1 ? '' : 's'), 'danger', 'fa-triangle-exclamation');
        }
        if ($days === 0) {
            return ui_badge('Due today', 'warning', 'fa-fire');
        }
        if ($days === 1) {
            return ui_badge('Due tomorrow', 'warning', 'fa-clock');
        }
        if ($days <= 3) {
            return ui_badge('Due in ' . $days . ' days', 'warning', 'fa-clock');
        }
        return ui_badge($label, 'neutral', 'fa-calendar-day');
    }
}

if (!function_exists('ui_progress')) {
    /**
     * Labelled progress bar. Echo directly.
     *
     * Guarded against a null percentage: a bar with an unknown width must not
     * render as 100% full, which was the previous behaviour when a course had
     * no graded work yet.
     */
    function ui_progress($pct, $label = '', $tone = null) {
        if ($pct === null) {
            $pct = 0;
        }
        $pct = max(0, min(100, (float) $pct));
        $width = $pct > 0 ? $pct . '%' : '0%';

        if ($tone === null) {
            $tone = ui_grade_tone($pct);
        }
        $bars = [
            'success' => '#16a34a',
            'primary' => 'var(--brand-600)',
            'warning' => '#d97706',
            'danger'  => '#dc2626',
            'neutral' => 'var(--text-subtle)',
        ];
        $color = isset($bars[$tone]) ? $bars[$tone] : $bars['primary'];

        $html = '<div class="progress-label">';
        $html .= '<span>' . e($label) . '</span>';
        $html .= '<span class="fw-semibold">' . (floor($pct) == $pct ? (int) $pct : $pct) . '%</span>';
        $html .= '</div>';
        $html .= '<div class="progress" role="progressbar" aria-valuenow="' . e_attr(floor($pct)) . '"'
            . ' aria-valuemin="0" aria-valuemax="100"'
            . ' aria-label="' . e_attr($label !== '' ? $label : 'Progress') . '">';
        $html .= '<div class="progress-bar" style="width:' . e_attr($width) . ';background:' . $color . '"></div>';
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('ui_stat')) {
    /**
     * Metric tile for dashboard rows. Echo directly.
     *
     *     ui_stat('Enrolled courses', 4, 'fa-book-reader', 'brand')
     */
    function ui_stat($label, $value, $icon = 'fa-chart-simple', $tone = 'brand', $hint = '') {
        $hintHtml = $hint !== ''
            ? '<span class="stat-card__hint">' . e($hint) . '</span>'
            : '';

        return '<div class="stat-card stat-card--' . e_attr($tone) . '">'
            . '<span class="stat-card__icon"><i class="fas ' . e_attr($icon) . '" aria-hidden="true"></i></span>'
            . '<span>'
            . '<span class="stat-card__value">' . e($value) . '</span>'
            . '<span class="stat-card__label">' . e($label) . $hintHtml . '</span>'
            . '</span>'
            . '</div>';
    }
}

if (!function_exists('ui_empty_state')) {
    /**
     * Placeholder for an empty list. Echo directly.
     *
     *     ui_empty_state('fa-inbox', 'No submissions yet', 'Students can submit once the assignment is published.')
     */
    function ui_empty_state($icon, $title, $text = '', $actionHtml = '') {
        $html = '<div class="empty-state">';
        $html .= '<span class="empty-state__icon"><i class="fas ' . e_attr($icon) . '" aria-hidden="true"></i></span>';
        $html .= '<p class="empty-state__title">' . e($title) . '</p>';
        if ($text !== '') {
            $html .= '<p class="empty-state__text">' . e($text) . '</p>';
        }
        if ($actionHtml !== '') {
            $html .= $actionHtml;
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('ui_page_header')) {
    /**
     * Consistent page title block. Echo directly.
     *
     *     ui_page_header('My Courses', 'Browse the catalogue and track progress.', $actions)
     */
    function ui_page_header($title, $subtitle = '', $actionsHtml = '', $eyebrow = '') {
        $html = '<header class="page-head"><div>';
        if ($eyebrow !== '') {
            $html .= '<div class="text-uppercase text-subtle fw-bold mb-1" style="font-size:.6875rem;letter-spacing:.08em">'
                . e($eyebrow) . '</div>';
        }
        $html .= '<h1 class="page-head__title">' . e($title) . '</h1>';
        if ($subtitle !== '') {
            $html .= '<p class="page-head__subtitle">' . e($subtitle) . '</p>';
        }
        $html .= '</div>';
        $html .= '<div class="page-head__actions">' . $actionsHtml . '</div>';
        $html .= '</header>';
        return $html;
    }
}

if (!function_exists('ui_breadcrumbs')) {
    /**
     * Breadcrumb trail from an array of ['label' => ..., 'url' => ...] pairs.
     * The final entry has no url and is rendered as the current page.
     * Echo directly.
     */
    function ui_breadcrumbs(array $crumbs, $homeLabel = 'Dashboard', $homeUrl = 'dashboard.php') {
        $html = '<nav aria-label="Breadcrumb"><ol class="breadcrumb">';

        $html .= '<li class="breadcrumb-item"><a href="' . e_attr(app_url($homeUrl)) . '">'
            . '<i class="fas fa-house" aria-hidden="true"></i> ' . e($homeLabel) . '</a></li>';

        $last = count($crumbs) - 1;
        foreach ($crumbs as $i => $crumb) {
            $label = is_array($crumb) ? $crumb['label'] : (string) $crumb;
            $url = is_array($crumb) ? ($crumb['url'] ?? null) : null;

            $html .= '<li class="breadcrumb-item' . ($i === $last ? ' active' : '') . '">';
            if ($url !== null && $i !== $last) {
                $html .= '<a href="' . e_attr(app_url($url)) . '">' . e($label) . '</a>';
            } else {
                $html .= e($label);
            }
            $html .= '</li>';
        }

        $html .= '</ol></nav>';
        return $html;
    }
}

if (!function_exists('ui_detail_row')) {
    /**
     * One label/value pair for a detail panel. Echo directly.
     */
    function ui_detail_row($label, $valueHtml, $plain = true) {
        return '<div class="detail-row"><dt>' . e($label) . '</dt>'
            . '<dd' . ($plain ? ' class="is-plain"' : '') . '>' . $valueHtml . '</dd></div>';
    }
}

if (!function_exists('ui_textarea')) {
    /**
     * Escape a value for redisplay inside a <textarea>.
     */
    function ui_textarea_value($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ui_form_value')) {
    /**
     * Redisplay a submitted value, or a fallback when the field was not sent.
     *
     * Only safe for text-like fields: never pass a file upload or an array.
     */
    function ui_form_value($field, $fallback = '') {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            return (string) $_POST[$field];
        }
        return $fallback;
    }
}

if (!function_exists('ui_radio_options')) {
    /**
     * Render a list of options as stacked radio buttons. Echo directly.
     *
     *     ui_radio_options('assignment_type', ['file', 'text', 'both'], 'file')
     */
    function ui_radio_options($name, array $options, $selected, $prefix = '') {
        $html = '';
        foreach ($options as $value => $label) {
            $id = 'opt-' . preg_replace('/[^a-z0-9]+/i', '-', $name . '-' . $value);
            $checked = ((string) $value === (string) $selected) ? ' checked' : '';
            $html .= '<div class="form-check">';
            $html .= '<input class="form-check-input" type="radio" name="' . e_attr($name) . '"'
                . ' id="' . e_attr($id) . '" value="' . e_attr($value) . '"' . $checked . '>';
            $html .= '<label class="form-check-label" for="' . e_attr($id) . '">' . e($label) . '</label>';
            $html .= '</div>';
        }
        return $html;
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
