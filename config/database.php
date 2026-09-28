<?php
/**
 * Database connection bootstrap.
 *
 * Credentials are NOT stored in version control. They are read from
 * config/local.php, which is gitignored. Copy config/local.example.php to
 * config/local.php and fill in the values for your host.
 *
 * On GoogieHost: create a MySQL database in DirectAdmin, then copy the
 * generated database name, username and password into config/local.php.
 */

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host     = DB_HOST;
        $this->db_name  = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements: prevents a class of SQL injection bugs.
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $exception) {
            // Log the real cause for the site owner...
            error_log("Database connection error: " . $exception->getMessage());

            // ...but never hand the raw DSN / credential detail to a visitor.
            if (defined('APP_DEBUG') && APP_DEBUG) {
                throw new Exception("Database connection failed: " . $exception->getMessage());
            }
            throw new Exception("Database connection failed. Please try again later.");
        }
        return $this->conn;
    }
}
