<?php
/**
 * Base Model
 *
 * Provides common database operations for all models.
 */

require_once __DIR__ . '/../config/Database.php';

class Model {
    protected string $table;
    protected string $primaryKey = 'id';

    /**
     * Find record by ID
     */
    public function find(int $id): ?array {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $result = Database::query($sql, [$id]);
        return $result[0] ?? null;
    }

    /**
     * Find all records
     */
    public function all(): array {
        return Database::query("SELECT * FROM {$this->table}");
    }

    /**
     * Find records with conditions
     */
    public function where(array $conditions, string $orderBy = null, int $limit = null): array {
        $where = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            if (is_null($value)) {
                $where[] = "$key IS NULL";
            } else {
                $where[] = "$key = ?";
                $params[] = $value;
            }
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where);

        if ($orderBy) {
            $sql .= " ORDER BY $orderBy";
        }

        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        return Database::query($sql, $params);
    }

    /**
     * Find single record with conditions
     */
    public function first(array $conditions): ?array {
        $results = $this->where($conditions, null, 1);
        return $results[0] ?? null;
    }

    /**
     * Create new record
     */
    public function create(array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        Database::execute($sql, array_values($data));

        return (int) Database::lastInsertId();
    }

    /**
     * Update record by ID
     */
    public function update(int $id, array $data): bool {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$this->table} SET $set WHERE {$this->primaryKey} = ?";

        $result = Database::execute($sql, [...array_values($data), $id]);
        return $result > 0;
    }

    /**
     * Delete record by ID
     */
    public function delete(int $id): bool {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $result = Database::execute($sql, [$id]);
        return $result > 0;
    }

    /**
     * Count records
     */
    public function count(array $conditions = []): int {
        if (empty($conditions)) {
            $result = Database::query("SELECT COUNT(*) as count FROM {$this->table}");
        } else {
            $where = [];
            $params = [];
            foreach ($conditions as $key => $value) {
                $where[] = "$key = ?";
                $params[] = $value;
            }
            $result = Database::query(
                "SELECT COUNT(*) as count FROM {$this->table} WHERE " . implode(' AND ', $where),
                $params
            );
        }
        return (int) ($result[0]['count'] ?? 0);
    }

    /**
     * Check if record exists
     */
    public function exists(int $id): bool {
        $sql = "SELECT 1 FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $result = Database::query($sql, [$id]);
        return !empty($result);
    }
}
