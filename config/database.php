<?php
class Database {
    private $host = "localhost";
    private $db_name = "elearning_peer_review";
    private $username = "root";
    private $password = "";
    public $conn;

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
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            if (ini_get('display_errors')) {
                throw new Exception("Database connection failed: " . $exception->getMessage());
            } else {
                throw new Exception("Database connection failed. Please try again later.");
            }
        }
        return $this->conn;
    }
}
?>