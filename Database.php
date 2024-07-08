<?php
class Database {
    private $mysqli;
    private $config;

    public function __construct($config) {
        $this->config = $config;
        $this->connect();
        $this->createTables();
    }

    private function connect() {
        $this->mysqli = new mysqli(
            $this->config['db']['host'],
            $this->config['db']['user'],
            $this->config['db']['pass'],
            $this->config['db']['name']
        );

        if ($this->mysqli->connect_error) {
            throw new Exception("Connection failed: " . $this->mysqli->connect_error);
        }

        $this->mysqli->set_charset("utf8mb4");
    }

    private function createTables() {
        $this->mysqli->query("CREATE TABLE IF NOT EXISTS jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL,
            file1 VARCHAR(255) NOT NULL,
            file2 VARCHAR(255) NOT NULL,
            ignore_options TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $this->mysqli->query("CREATE TABLE IF NOT EXISTS results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id INT,
            differences LONGTEXT,
            stats LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
        )");
    }

    public function query($sql, $params = []) {
        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Failed to prepare statement: " . $this->mysqli->error);
        }
        
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        return $result;
    }

    public function insert($table, $data) {
        $keys = array_keys($data);
        $values = array_values($data);
        $sql = "INSERT INTO $table (" . implode(", ", $keys) . ") VALUES (" . implode(", ", array_fill(0, count($keys), "?")) . ")";
        
        $this->query($sql, $values);
        return $this->mysqli->insert_id;
    }

    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
        }
        $sql = "UPDATE $table SET " . implode(", ", $set) . " WHERE $where";
        
        $params = array_merge(array_values($data), $whereParams);
        $this->query($sql, $params);
        return $this->mysqli->affected_rows;
    }

    public function fetchOne($sql, $params = []) {
        $result = $this->query($sql, $params);
        return $result->fetch_assoc();
    }

    public function fetchAll($sql, $params = []) {
        $result = $this->query($sql, $params);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function beginTransaction() {
        $this->mysqli->begin_transaction();
    }

    public function commit() {
        $this->mysqli->commit();
    }

    public function rollback() {
        $this->mysqli->rollback();
    }

    public function lastInsertId() {
        return $this->mysqli->insert_id;
    }

    public function close() {
        $this->mysqli->close();
    }
}
