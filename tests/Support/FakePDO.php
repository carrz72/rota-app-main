<?php
declare(strict_types=1);

class FakePDO
{
    private array $roles = [];
    private array $shiftRows = [];
    private int $lastInsertId = 0;

    public function setRole(int $id, array $row): void
    {
        $this->roles[$id] = $row;
    }

    public function setShiftRow(int $id, array $row): void
    {
        $this->shiftRows[$id] = $row;
    }

    public function prepare(string $sql): FakePDOStatement
    {
        return new FakePDOStatement($this, $sql);
    }

    public function lastInsertId(): int
    {
        return $this->lastInsertId;
    }

    public function setLastInsertId(int $id): void
    {
        $this->lastInsertId = $id;
    }

    // Internal getters used by statements
    public function findRoleById(int $id): ?array
    {
        return $this->roles[$id] ?? null;
    }

    public function findShiftRowById(int $id): ?array
    {
        return $this->shiftRows[$id] ?? null;
    }
}

class FakePDOStatement
{
    private FakePDO $pdo;
    private string $sql;
    private ?array $result = null;
    private int $rowCount = 0;

    public function __construct(FakePDO $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool
    {
        // Very small router based on the target table
        if (stripos($this->sql, 'FROM roles') !== false) {
            $id = (int)($params[0] ?? 0);
            $row = $this->pdo->findRoleById($id);
            $this->result = $row ?: null;
            $this->rowCount = $row ? 1 : 0;
            return true;
        }

        if (stripos($this->sql, 'FROM shifts') !== false) {
            $id = (int)($params[0] ?? 0);
            $row = $this->pdo->findShiftRowById($id);
            $this->result = $row ?: null;
            $this->rowCount = $row ? 1 : 0;
            return true;
        }

        // Notifications duplicate-check
        if (stripos($this->sql, 'FROM notifications') !== false) {
            // Assume none exist in unit tests
            $this->result = null;
            $this->rowCount = 0;
            return true;
        }

        // INSERTs or others: treat as successful no-op
        if (stripos($this->sql, 'INSERT ') === 0 || stripos($this->sql, 'UPDATE ') === 0 || stripos($this->sql, 'DELETE ') === 0) {
            $this->rowCount = 1;
            return true;
        }

        $this->result = null;
        $this->rowCount = 0;
        return true;
    }

    public function fetch($mode = null)
    {
        return $this->result;
    }

    public function fetchAll($mode = null): array
    {
        return $this->result ? [$this->result] : [];
    }

    public function fetchColumn(int $column = 0)
    {
        if (!$this->result) return false;
        $values = array_values($this->result);
        return $values[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }
}
