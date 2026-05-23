<?php
/**
 * Template Model
 */

require_once __DIR__ . '/Model.php';

class Template extends Model {
    protected string $table = 'templates';

    /**
     * Get active templates only
     */
    public function active(): array {
        return Database::query(
            "SELECT id, name, slug, description, thumbnail_url, category, is_premium
             FROM templates WHERE is_active = TRUE ORDER BY category, name"
        );
    }

    /**
     * Get template by slug
     */
    public function findBySlug(string $slug): ?array {
        return $this->first(['slug' => $slug]);
    }

    /**
     * Get templates by category
     */
    public function getByCategory(string $category): array {
        return Database::query(
            "SELECT id, name, slug, description, thumbnail_url, category
             FROM templates WHERE is_active = TRUE AND category = ?",
            [$category]
        );
    }
}
