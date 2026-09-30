

<?php
class Auth
{
    /**
     * The only role a visitor may create for themselves.
     *
     * Public sign-up can never mint an instructor or admin account. Those are
     * provisioned out of band (install/create_admin.php, then role promotion).
     */
    const PUBLIC_REGISTRATION_ROLE = 'student';

    private $db;
    private $conn;

    /** @var string|null User-facing reason the last attempt failed. */
    private $last_error = null;

    public function __construct($database)
    {
        $this->db = $database;
        $this->conn = $this->db->getConnection();
    }

    /**
     * Create a public self-service account.
     *
     * There is deliberately no $role parameter. The role used to be accepted
     * from the caller and validated against a whitelist that included
     * 'admin', so anyone who posted role=admin to register.php became an
     * administrator. Making the role unrepresentable at this boundary means
     * the escalation cannot be reintroduced by a caller that forgets to
     * sanitise its input.
     *
     * @return bool True on success. Use getLastError() for the reason on failure.
     */
    public function register($username, $email, $password, $firstName, $lastName)
    {
        $this->last_error = null;

        $username   = trim((string) $username);
        $email      = trim((string) $email);
        $firstName  = trim((string) $firstName);
        $lastName   = trim((string) $lastName);

        $error = $this->validateRegistrationInput($username, $email, $password, $firstName, $lastName);
        if ($error !== true) {
            $this->last_error = $error;
            return false;
        }

        // Check username/email availability.
        try {
            $duplicate = $this->userExists($username, $email);
        } catch (RuntimeException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }

        if ($duplicate) {
            $this->last_error = 'That username or email address is already registered.';
            return false;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $query = "INSERT INTO users (username, email, password_hash, first_name, last_name, role, is_active)
                  VALUES (:username, :email, :password_hash, :first_name, :last_name, :role, 1)";

        try {
            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(":username", $username);
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":password_hash", $passwordHash);
            $stmt->bindParam(":first_name", $firstName);
            $stmt->bindParam(":last_name", $lastName);
            $stmt->bindParam(":role", self::PUBLIC_REGISTRATION_ROLE);

            if ($stmt->execute()) {
                return true;
            }

            $this->last_error = 'The account could not be created. Please try again.';
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            // 23000 = integrity constraint violation, i.e. a duplicate
            // username/email that slipped past the pre-check.
            $this->last_error = ($e->getCode() === '23000')
                ? 'That username or email address is already registered.'
                : 'The account could not be created. Please try again.';
        }

        return false;
    }

    /**
     * Reason the last login/register attempt failed, for showing to the user.
     * Returns null when the last attempt succeeded.
     */
    public function getLastError()
    {
        return $this->last_error;
    }

    /**
     * Verify credentials and start a session.
     *
     * @return bool True on success; getLastError() explains a failure.
     */
    public function login($email, $password)
    {
        $this->last_error = null;

        $email = trim((string) $email);

        if ($email === '' || $password === '') {
            $this->last_error = 'Enter your email address and password.';
            return false;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Do not reveal whether the address is registered.
            $this->last_error = 'Invalid email address or password.';
            return false;
        }

        $query = "SELECT user_id, username, email, password_hash, role, first_name, last_name, is_active
                  FROM users WHERE email = :email";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            if ($stmt->fetchColumn() !== 1) {
                // Constant-ish work factor so a missing account and a wrong
                // password take comparable time, and the same generic message
                // is returned for both.
                password_verify($password, '$2y$10$usesomesillystringforsalttoslowdownattackers0000000');
                $this->last_error = 'Invalid email address or password.';
                error_log("Failed login attempt for unknown email: " . $email);
                return false;
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!password_verify($password, $user['password_hash'])) {
                error_log("Failed login attempt for email: " . $email);
                $this->last_error = 'Invalid email address or password.';
                return false;
            }

            if (!(int) $user['is_active']) {
                error_log("Login attempt for inactive user: " . $email);
                $this->last_error = 'This account has been deactivated. Please contact your administrator.';
                return false;
            }

            // Transparently upgrade the hash if PHP's default algorithm moved on.
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $this->rehashPassword($user['user_id'], $password);
            }

            // Regenerate the session ID to prevent session fixation.
            session_regenerate_id(true);

            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name']  = $user['last_name'];
            $_SESSION['login_time'] = time();
            $_SESSION['created']    = time();
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $this->updateLastLogin($user['user_id']);

            return true;
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $this->last_error = 'Sign-in is temporarily unavailable. Please try again shortly.';
            return false;
        }
    }

    /**
     * Re-save a password hash after PHP's default algorithm or cost changes.
     */
    private function rehashPassword($user_id, $password)
    {
        try {
            $stmt = $this->conn->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :user_id");
            $stmt->bindParam(':hash', password_hash($password, PASSWORD_DEFAULT));
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
        } catch (PDOException $e) {
            // Non-fatal: the existing hash still verifies.
            error_log("Password rehash error: " . $e->getMessage());
        }
    }

    public function isLoggedIn()
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        // Additional security checks
        if (!isset($_SESSION['login_time']) || !isset($_SESSION['ip_address']) || !isset($_SESSION['user_agent'])) {
            return false;
        }

        // Check session timeout (8 hours)
        if (time() - $_SESSION['login_time'] > 8 * 60 * 60) {
            $this->endSession();
            return false;
        }

        // A user agent or IP change usually means a hijacked session, so drop
        // it. Both sides are read with ?? '' because a hardened server or some
        // proxies omit these headers, and an undefined index must not sign the
        // user out or emit a warning into the page.
        if ($_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            $this->endSession();
            return false;
        }

        if ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $this->endSession();
            return false;
        }

        return true;
    }

    /**
     * Destroy the current session without redirecting.
     *
     * Used when isLoggedIn() decides an existing session is no longer valid.
     * logout() cannot be reused here because it redirects, which would fight
     * the redirect the calling page is about to perform.
     */
    private function endSession()
    {
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }

    /**
     * Send a Location header and stop.
     *
     * The target is forced to be an in-app path: an absolute URL or an
     * embedded CR/LF would let a caller bounce a just-authenticated user to
     * another site (open redirect / header injection).
     */
    public function redirect($url, $permanent = false)
    {
        // Strip CR/LF and reject anything that looks like a scheme or a
        // protocol-relative URL.
        $url = (string) preg_replace('/[\r\n]+/', '', $url);

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || strpos($url, '//') === 0) {
            $url = 'dashboard.php';
        }

        // Build the absolute in-app path. app_url() applies APP_ROOT, so this
        // works for a subdirectory install and keeps existing callers correct.
        $url = ltrim($url, '/');
        $target = function_exists('app_url') ? app_url($url) : $url;

        if (headers_sent()) {
            echo '<p>Redirecting to <a href="' . e($target) . '">' . e($target) . '</a></p>';
            exit;
        }

        if ($permanent) {
            http_response_code(301);
        }
        header('Location: ' . $target);
        exit();
    }

    public function logout()
    {
        $this->endSession();

        // Open a fresh session purely to carry the "you signed out" notice to
        // the login screen. A plain query string would also work but leaks into
        // the browser history and Referer headers.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['logged_out'] = true;
        }

        $this->redirect('login.php');
    }

    public function getUserRole()
    {
        return $_SESSION['role'] ?? null;
    }

    public function getUserID()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Gate a page behind a role.
     *
     * Accepts either a single role or a list, so shared pages can allow
     * ['student', 'instructor'] without duplicating this method.
     */
    public function requireRole($requiredRole)
    {
        if (!$this->isLoggedIn()) {
            // Remember where the visitor was heading so login can return them.
            if (!isset($_SESSION['redirect_after_login'])) {
                $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
            }
            $this->redirect('login.php');
        }

        $allowed = is_array($requiredRole) ? $requiredRole : [$requiredRole];
        if (!in_array($_SESSION['role'], $allowed, true)) {
            error_log(
                'Unauthorized access attempt: user ' . $_SESSION['user_id'] . ' (role '
                . $_SESSION['role'] . ') tried to access ' . implode('/', $allowed)
                . ' content at ' . ($_SERVER['REQUEST_URI'] ?? '?')
            );
            $this->forbidden();
        }
    }

    public function hasRole($role)
    {
        if (!$this->isLoggedIn()) {
            return false;
        }
        return is_array($role)
            ? in_array($_SESSION['role'], $role, true)
            : $_SESSION['role'] === $role;
    }

    /**
     * Render a styled 403 and stop. Replaces the bare plain-text response.
     */
    private function forbidden()
    {
        if (!headers_sent()) {
            http_response_code(403);
        }

        $home = function_exists('app_url') ? app_url('dashboard.php') : 'dashboard.php';
        $css  = function_exists('asset_url') ? asset_url('css/app.css') : '';
        $esc  = function_exists('e') ? 'e' : 'htmlspecialchars';

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access denied</title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
            <?php if ($css !== ''): ?>
                <link rel="stylesheet" href="<?php echo $esc($css); ?>">
            <?php endif; ?>
        </head>
        <body>
            <div class="d-flex align-items-center justify-content-center" style="min-height:100vh;padding:2rem 1rem">
                <div class="surface text-center" style="max-width:30rem;width:100%">
                    <div class="surface__body" style="padding:2rem">
                        <div class="empty-state__icon mx-auto mb-3" style="background:#fef3c7;color:#b45309">
                            <i class="fas fa-lock" aria-hidden="true"></i>
                        </div>
                        <h1 class="h4 mb-2">Access denied</h1>
                        <p class="text-muted mb-3">
                            Your account does not have permission to view this page. If you believe
                            this is a mistake, ask an administrator to check your role.
                        </p>
                        <a class="btn btn-primary" href="<?php echo $esc($home); ?>">
                            <i class="fas fa-house me-1" aria-hidden="true"></i> Back to dashboard
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    public function getUserInfo()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'email' => $_SESSION['email'],
            'role' => $_SESSION['role'],
            'first_name' => $_SESSION['first_name'],
            'last_name' => $_SESSION['last_name']
        ];
    }

    // Private helper methods

    /**
     * Validate registration input.
     *
     * @return true|string True when valid, otherwise a user-facing reason.
     */
    private function validateRegistrationInput($username, $email, $password, $firstName, $lastName)
    {
        if ($username === '' || $email === '' || $password === '' || $firstName === '' || $lastName === '') {
            return 'All fields are required.';
        }

        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            return 'First and last name must be 100 characters or fewer.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return 'Enter a valid email address.';
        }

        // Alphanumeric plus underscore, 3-20 characters.
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return 'Username must be 3-20 characters, using letters, numbers and underscores only.';
        }

        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long.';
        }

        // A password made only of one repeated character passes the length
        // check but is trivially guessable.
        if (preg_match('/^(.)\1+$/', $password)) {
            return 'Password must not be a single repeated character.';
        }

        return true;
    }

    private function userExists($username, $email)
    {
        $query = "SELECT user_id FROM users WHERE username = :username OR email = :email LIMIT 1";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":username", $username);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            // On error, fail CLOSED. The old code returned false ("no such
            // user") so the insert proceeded, which turned a transient database
            // fault into a confusing duplicate-key error for the visitor.
            error_log("User existence check error: " . $e->getMessage());
            throw new RuntimeException('Could not verify the username/email. Please try again.', 0, $e);
        }
    }

    private function updateLastLogin($user_id)
    {
        $query = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":user_id", $user_id);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Last login update error: " . $e->getMessage());
        }
    }
}
