

<?php
class Auth
{
    private $db;
    private $conn;

    public function __construct($database)
    {
        $this->db = $database;
        $this->conn = $this->db->getConnection();
    }

    public function register($username, $email, $password, $firstName, $lastName, $role)
    {
        // Validate input
        if (!$this->validateRegistrationInput($username, $email, $password, $firstName, $lastName, $role)) {
            return false;
        }

        // Check if username or email already exists
        if ($this->userExists($username, $email)) {
            return false;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $query = "INSERT INTO users (username, email, password_hash, first_name, last_name, role) 
                  VALUES (:username, :email, :password_hash, :first_name, :last_name, :role)";

        try {
            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(":username", $username);
            $stmt->bindParam(":email", $email);
            $stmt->bindParam(":password_hash", $passwordHash);
            $stmt->bindParam(":first_name", $firstName);
            $stmt->bindParam(":last_name", $lastName);
            $stmt->bindParam(":role", $role);

            if ($stmt->execute()) {
                return true;
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
        }
        return false;
    }

    public function login($email, $password)
    {
        // Validate input
        if (empty($email) || empty($password)) {
            return false;
        }

        $query = "SELECT user_id, username, email, password_hash, role, first_name, last_name, is_active 
                  FROM users WHERE email = :email";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Check if user is active
                if (!$user['is_active']) {
                    error_log("Login attempt for inactive user: " . $email);
                    return false;
                }

                // Verify password
                if (password_verify($password, $user['password_hash'])) {
                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['created'] = time();
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

                    // Update last login
                    $this->updateLastLogin($user['user_id']);

                    return true;
                } else {
                    // Log failed login attempts
                    error_log("Failed login attempt for email: " . $email);
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
        }
        return false;
    }

    public function isLoggedIn()
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        // Additional security checks
        if (!isset($_SESSION['login_time']) || !isset($_SESSION['ip_address']) || !isset($_SESSION['user_agent'])) {
            return false;
        }

        // Check session timeout (8 hours)
        if (time() - $_SESSION['login_time'] > 8 * 60 * 60) {
            $this->logout();
            return false;
        }

        // Check if IP address changed
        if ($_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            $this->logout();
            return false;
        }

        // Check if user agent changed
        if ($_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            $this->logout();
            return false;
        }

        return true;
    }

    public function redirect($url, $permanent = false)
    {
        if ($permanent) {
            header("HTTP/1.1 301 Moved Permanently");
        }
        header("Location: $url");
        exit();
    }

    public function logout()
    {
        // Unset all session variables
        $_SESSION = array();

        // Delete session cookie
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

        // Destroy session
        session_destroy();

        // Redirect to login page
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

    public function requireRole($requiredRole)
    {
        if (!$this->isLoggedIn()) {
            $this->redirect('login.php');
        }

        if ($_SESSION['role'] !== $requiredRole) {
            // Log unauthorized access attempt
            error_log("Unauthorized access attempt: User {$_SESSION['user_id']} tried to access {$requiredRole} content");

            // Show error page or redirect to dashboard
            header('HTTP/1.0 403 Forbidden');
            echo "Access Denied. You don't have permission to access this page.";
            exit();
        }
    }

    public function hasRole($role)
    {
        return $this->isLoggedIn() && $_SESSION['role'] === $role;
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
    private function validateRegistrationInput($username, $email, $password, $firstName, $lastName, $role)
    {
        if (empty($username) || empty($email) || empty($password) || empty($firstName) || empty($lastName) || empty($role)) {
            return false;
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Validate username (alphanumeric, 3-20 characters)
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return false;
        }

        // Validate password strength (at least 8 characters)
        if (strlen($password) < 8) {
            return false;
        }

        // Validate role
        $allowedRoles = ['student', 'instructor', 'admin'];
        if (!in_array($role, $allowedRoles)) {
            return false;
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

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("User existence check error: " . $e->getMessage());
            return false; // Return false on error so registration can proceed
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
