<?php
/**
 * User Model
 */

require_once __DIR__ . '/Model.php';

class User extends Model {
    protected string $table = 'users';

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array {
        return $this->first(['email' => $email]);
    }

    /**
     * Create user with hashed password
     */
    public function createWithPassword(array $data): int {
        if (isset($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        return $this->create($data);
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $email, string $password): ?array {
        $user = $this->findByEmail($email);
        if (!$user || !isset($user['password_hash'])) return null;

        // Check if user is active
        if (!($user['is_active'] ?? true)) {
            return null;
        }

        if (password_verify($password, $user['password_hash'])) {
            unset($user['password_hash']);
            return $user;
        }
        return null;
    }

    /**
     * Get user without sensitive fields (password)
     */
    public function getSafeUser(int $id): ?array {
        $user = $this->find($id);
        if (!$user) return null;
        unset($user['password_hash']);
        return $user;
    }

    /**
     * Get user's websites
     */
    public function websites(int $userId): array {
        return Database::query(
            "SELECT * FROM websites WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
    }

    /**
     * Get user's social accounts
     */
    public function socialAccounts(int $userId): array {
        return Database::query(
            "SELECT id, provider, provider_username, provider_avatar_url, is_connected, last_fetched_at
             FROM social_accounts WHERE user_id = ?",
            [$userId]
        );
    }

    /**
     * Update password (hash and store)
     */
    public function updatePassword(int $userId, string $newPassword): bool {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this->update($userId, ['password_hash' => $hash]);
    }

    /**
     * Get non-admin users with pagination and search
     */
    public function getNonAdminUsers(int $page = 1, int $perPage = 10, string $search = ''): array {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $where = "is_admin = FALSE";

        if (!empty($search)) {
            $where .= " AND (full_name LIKE ? OR email LIKE ?)";
            $searchTerm = "%$search%";
            $params = [$searchTerm, $searchTerm];
        }

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM {$this->table} WHERE $where";
        $countResult = Database::query($countSql, $params);
        $total = $countResult[0]['total'] ?? 0;

        // Get users
        $sql = "SELECT id, email, full_name, avatar_url, bio, is_admin, is_active, created_at, updated_at
                FROM {$this->table}
                WHERE $where
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $users = Database::query($sql, $params);

        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    /**
     * Activate user
     */
    public function activate(int $userId): bool {
        return $this->update($userId, ['is_active' => true]);
    }

    /**
     * Deactivate user
     */
    public function deactivate(int $userId): bool {
        return $this->update($userId, ['is_active' => false]);
    }

    /**
     * Get total non-admin users count
     */
    public function getTotalNonAdminUsers(): int {
        $result = Database::query(
            "SELECT COUNT(*) as total FROM {$this->table} WHERE is_admin = FALSE"
        );
        return (int) ($result[0]['total'] ?? 0);
    }

    /**
     * Get monthly user registrations
     */
    public function getMonthlyRegistrations(int $year = null): array {
        $year = $year ?? date('Y');
        $sql = "SELECT
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as total
                FROM {$this->table}
                WHERE is_admin = FALSE
                    AND YEAR(created_at) = ?
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC";
        return Database::query($sql, [$year]);
    }
}
