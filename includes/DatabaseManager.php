<?php
/**
 * Enhanced Database Manager with Query Builder
 * Provides common database operations and query building
 */

class DatabaseManager
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    /**
     * Execute a prepared statement with error handling
     */
    public function execute($query, $params = [])
    {
        try {
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);
            return ['success' => true, 'statement' => $stmt, 'affected_rows' => $stmt->rowCount()];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'code' => $e->getCode()];
        }
    }

    /**
     * Fetch single record
     */
    public function fetchOne($query, $params = [])
    {
        $result = $this->execute($query, $params);
        if ($result['success']) {
            return $result['statement']->fetch(PDO::FETCH_ASSOC);
        }
        return false;
    }

    /**
     * Fetch multiple records
     */
    public function fetchAll($query, $params = [])
    {
        $result = $this->execute($query, $params);
        if ($result['success']) {
            return $result['statement']->fetchAll(PDO::FETCH_ASSOC);
        }
        return [];
    }

    /**
     * Insert record with automatic error handling
     */
    public function insert($table, $data)
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $query = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $result = $this->execute($query, array_values($data));

        if ($result['success']) {
            return ['success' => true, 'id' => $this->conn->lastInsertId()];
        }

        return ['success' => false, 'error' => $result['error'], 'code' => $result['code']];
    }

    /**
     * Update record with automatic error handling
     */
    public function update($table, $data, $where, $whereParams = [])
    {
        $setClauses = [];
        foreach (array_keys($data) as $column) {
            $setClauses[] = "{$column} = ?";
        }

        $query = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);

        return $this->execute($query, $params);
    }

    /**
     * Delete record with automatic error handling
     */
    public function delete($table, $where, $whereParams = [])
    {
        $query = "DELETE FROM {$table} WHERE {$where}";
        return $this->execute($query, $whereParams);
    }

    /**
     * Count records
     */
    public function count($table, $where = '', $whereParams = [])
    {
        $query = "SELECT COUNT(*) as count FROM {$table}";
        if ($where) {
            $query .= " WHERE {$where}";
        }

        $result = $this->fetchOne($query, $whereParams);
        return $result ? $result['count'] : 0;
    }

    /**
     * Check if record exists
     */
    public function exists($table, $where, $whereParams = [])
    {
        return $this->count($table, $where, $whereParams) > 0;
    }

    /**
     * Get user with branch information
     */
    public function getUserWithBranch($userId)
    {
        return $this->fetchOne("
            SELECT u.*, b.name as branch_name, b.code as branch_code, b.status as branch_status
            FROM users u
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE u.id = ?
        ", [$userId]);
    }

    /**
     * Get users with branch filtering
     */
    public function getUsersWithBranches($branchFilter = null)
    {
        $query = "
            SELECT u.id, u.username, u.email, u.role, u.created_at, u.branch_id,
                   b.name as branch_name, b.code as branch_code
            FROM users u
            LEFT JOIN branches b ON u.branch_id = b.id
        ";

        $params = [];
        if ($branchFilter === 'none') {
            $query .= " WHERE u.branch_id IS NULL";
        } elseif ($branchFilter && $branchFilter !== 'all') {
            $query .= " WHERE u.branch_id = ?";
            $params[] = $branchFilter;
        }

        $query .= " ORDER BY u.id";

        return $this->fetchAll($query, $params);
    }

    /**
     * Get shifts with user and branch information
     */
    public function getShiftsWithDetails($dateFilter = null, $branchFilter = null)
    {
        $query = "
            SELECT s.*, u.username, u.branch_id, b.name as branch_name, r.name as role_name
            FROM shifts s
            JOIN users u ON s.user_id = u.id
            LEFT JOIN branches b ON u.branch_id = b.id
            LEFT JOIN roles r ON s.role_id = r.id
        ";

        $conditions = [];
        $params = [];

        if ($dateFilter) {
            $conditions[] = "s.shift_date = ?";
            $params[] = $dateFilter;
        }

        if ($branchFilter && $branchFilter !== 'all') {
            if ($branchFilter === 'none') {
                $conditions[] = "u.branch_id IS NULL";
            } else {
                $conditions[] = "u.branch_id = ?";
                $params[] = $branchFilter;
            }
        }

        if ($conditions) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY s.shift_date, s.start_time";

        return $this->fetchAll($query, $params);
    }

    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        return $this->conn->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit()
    {
        return $this->conn->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback()
    {
        return $this->conn->rollback();
    }
}
?>