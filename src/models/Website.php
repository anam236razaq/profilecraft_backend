<?php
/**
 * Website Model
 */

require_once __DIR__ . '/Model.php';

class Website extends Model {
    protected string $table = 'websites';

    /**
     * Get website with template info
     */
    public function getWithTemplate(int $id): ?array {
        $result = Database::query(
            "SELECT w.*, t.name as template_name, t.slug as template_slug
             FROM websites w
             LEFT JOIN templates t ON w.template_id = t.id
             WHERE w.id = ?",
            [$id]
        );
        return $result[0] ?? null;
    }

    /**
     * Get website by subdomain
     */
    public function findBySubdomain(string $subdomain): ?array {
        return $this->first(['subdomain' => $subdomain]);
    }

    /**
     * Get monthly website creations
     */
    public function getMonthlyCreations(int $year = null): array {
        $year = $year ?? date('Y');
        $sql = "SELECT
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as total
                FROM {$this->table}
                WHERE YEAR(created_at) = ?
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC";
        return Database::query($sql, [$year]);
    }
}
