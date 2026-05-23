<?php
/**
 * Website Controller
 */

class WebsiteController {
    private Website $websiteModel;
    private User $userModel;

    public function __construct() {
        $this->websiteModel = new Website();
        $this->userModel = new User();
    }

    /**
     * GET /api/websites
     * List current user's websites
     */
    public function index(array $data): void {
        $user = Auth::requireAuth();
        $websites = $this->userModel->websites($user['id']);
        Response::success($websites);
    }

    /**
     * POST /api/websites
     * Create new website
     */
    public function store(array $data): void {
        $user = Auth::requireAuth();

        // Plan-based website limits
        $planLimits = [
            'basic' => 1,
            'pro' => 5,
            'enterprise' => -1, // unlimited
        ];
        $userPlan = $user['plan'] ?? 'basic';
        $maxWebsites = $planLimits[$userPlan] ?? 1;

        // Check current website count (only for limited plans)
        if ($maxWebsites !== -1) {
            $currentCount = Database::query(
                "SELECT COUNT(*) as cnt FROM websites WHERE user_id = ?",
                [$user['id']]
            );
            $currentCount = $currentCount[0]['cnt'] ?? 0;
            if ($currentCount >= $maxWebsites) {
                $planName = ucfirst($userPlan);
                Response::error(
                    "Your {$planName} plan allows up to {$maxWebsites} website(s). Please upgrade to create more.",
                    403
                );
            }
        }

        // Validate
        if (empty($data['subdomain'])) {
            Response::validationError(['subdomain' => 'Subdomain is required']);
        }

        // Check if subdomain is available
        if ($this->websiteModel->findBySubdomain($data['subdomain'])) {
            Response::error('Subdomain is already taken', 409);
        }

        // Create website
        $websiteDataRaw = $data['website_data'] ?? null;

        // If website_data is already a JSON string, use it; otherwise encode it
        if ($websiteDataRaw !== null) {
            if (is_string($websiteDataRaw)) {
                // Already a JSON string, validate it's valid JSON
                json_decode($websiteDataRaw);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $websiteDataRaw = json_encode(['sections' => [], 'theme' => []]);
                }
            } else {
                // It's an array, encode it
                $websiteDataRaw = json_encode($websiteDataRaw);
            }
        } else {
            $websiteDataRaw = json_encode(['sections' => [], 'theme' => []]);
        }

        $websiteId = $this->websiteModel->create([
            'user_id' => $user['id'],
            'subdomain' => $data['subdomain'],
            'template_id' => $data['template_id'] ?? null,
            'website_data' => $websiteDataRaw,
            'is_published' => false,
        ]);

        $website = $this->websiteModel->find($websiteId);
        Response::created($website);
    }

    /**
     * GET /api/websites/{id}
     */
    public function show(array $data): void {
        $user = Auth::requireAuth();
        // Get website with template info
        $website = $this->websiteModel->getWithTemplate($data['id']);

        if (!$website) {
            Response::notFound('Website not found');
        }

        // Check ownership
        if ($website['user_id'] !== $user['id'] && !$user['is_admin']) {
            Response::forbidden('You do not own this website');
        }

        Response::success($website);
    }

    /**
     * PUT /api/websites/{id}
     */
    public function update(array $data): void {
        $user = Auth::requireAuth();
        $website = $this->websiteModel->find($data['id']);

        if (!$website) {
            Response::notFound('Website not found');
        }

        if ($website['user_id'] !== $user['id'] && !$user['is_admin']) {
            Response::forbidden('You do not own this website');
        }

        // Allowed fields to update
        $allowed = ['template_id', 'website_data', 'is_published', 'custom_domain'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        // Convert website_data array to JSON string if needed
        if (isset($updateData['website_data']) && is_array($updateData['website_data'])) {
            $updateData['website_data'] = json_encode($updateData['website_data']);
        }

        if (!empty($updateData)) {
            $this->websiteModel->update($data['id'], $updateData);
        }

        Response::success($this->websiteModel->find($data['id']));
    }

    /**
     * DELETE /api/websites/{id}
     */
    public function destroy(array $data): void {
        $user = Auth::requireAuth();
        $website = $this->websiteModel->find($data['id']);

        if (!$website) {
            Response::notFound('Website not found');
        }

        if ($website['user_id'] !== $user['id'] && !$user['is_admin']) {
            Response::forbidden('You do not own this website');
        }

        $this->websiteModel->delete($data['id']);
        Response::success(['message' => 'Website deleted successfully']);
    }

    /**
     * POST /api/websites/{id}/publish
     */
    public function publish(array $data): void {
        $user = Auth::requireAuth();
        $website = $this->websiteModel->find($data['id']);

        if (!$website) {
            Response::notFound('Website not found');
        }

        if ($website['user_id'] !== $user['id']) {
            Response::forbidden('You do not own this website');
        }

        $this->websiteModel->update($data['id'], [
            'is_published' => true,
            'published_at' => date('Y-m-d H:i:s')
        ]);

        Response::success(['message' => 'Website published successfully']);
    }

    /**
     * GET /api/templates
     * List available templates with search and pagination
     * Query params: search, page, per_page, category
     */
    public function templates(array $data): void {
        $templateModel = new Template();

        $page = max(1, intval($data['page'] ?? 1));
        $perPage = min(100, max(1, intval($data['per_page'] ?? 12)));
        $offset = ($page - 1) * $perPage;
        $search = trim($data['search'] ?? '');
        $category = trim($data['category'] ?? '');

        $whereConditions = ['is_active = TRUE'];
        $params = [];

        if ($search !== '') {
            $whereConditions[] = 'name LIKE ?';
            $params[] = "%$search%";
        }

        if ($category !== '' && $category !== 'all') {
            $whereConditions[] = 'category = ?';
            $params[] = $category;
        }

        $whereClause = implode(' AND ', $whereConditions);

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM templates WHERE $whereClause";
        $totalResult = Database::query($countSql, $params);
        $total = $totalResult[0]['total'] ?? 0;

        // Get paginated results
        $sql = "SELECT id, name, slug, description, thumbnail_url, category, is_premium, created_at
                FROM templates WHERE $whereClause
                ORDER BY category, name
                LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $templates = Database::query($sql, $params);

        Response::success([
            'data' => $templates,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ]);
    }

    /**
     * GET /api/templates/{id}
     */
    public function templateShow(array $data): void {
        $templateModel = new Template();
        $template = $templateModel->find($data['id']);

        if (!$template) {
            Response::notFound('Template not found');
        }

        Response::success($template);
    }
}
