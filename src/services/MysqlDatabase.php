<?php

namespace App\Services;

use PDO;
use PDOException;

class MysqlDatabase {
    private $pdo;
    private $connectionError = null;

    public function __construct() {
        $host = $_ENV['DB_HOST'] ?? 'mysql.navitag.net';
        $db   = $_ENV['DB_NAME'] ?? 'navitag';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? 'asdf';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Capture the error string to return it gracefully in method calls
            $this->connectionError = $e->getMessage();
        }
    }

    /**
     * Fetch a single row safely
     * Similar to: $stmt->fetch()
     */
    public function fetchOne(string $sql, array $params = []) {
        if ($this->connectionError) {
            return ['error' => 'Database connection failed', 'message' => $this->connectionError];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            // return empty array if no result found, rather than false, for consistency
            return $result ?: [];
        } catch (PDOException $e) {
            return ['error' => 'Query failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch all rows safely
     * Similar to: $stmt->fetchAll()
     */
    public function fetchAll(string $sql, array $params = []) {
        if ($this->connectionError) {
            return ['error' => 'Database connection failed', 'message' => $this->connectionError];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return ['error' => 'Query failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * Execute an Insert/Update/Delete
     * Returns ['status' => 'success', 'rows' => count] or ['error' => ...]
     */
    public function execute(string $sql, array $params = []) {
        if ($this->connectionError) {
            return ['error' => 'Database connection failed', 'message' => $this->connectionError];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return [
                'status' => 'success',
                'rows_affected' => $stmt->rowCount(),
                'last_insert_id' => $this->pdo->lastInsertId()
            ];
        } catch (PDOException $e) {
            return ['error' => 'Execution failed', 'message' => $e->getMessage()];
        }
    }
}