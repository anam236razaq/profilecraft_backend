<?php
/**
 * API Routes
 *
 * Register all API endpoints here.
 */

global $router;

// ============================================
// Public Routes (No Authentication Required)
// ============================================

$router->get('/health', function($data) {
    Response::success([
        'status' => 'ok',
        'app' => $_ENV['APP_NAME'] ?? 'ProfileCraft API',
        'time' => date('Y-m-d H:i:s')
    ]);
});

// ============================================
// Authentication Routes
// ============================================

$router->post('/auth/register', [AuthController::class, 'register']);
$router->post('/auth/login', [AuthController::class, 'login']);
$router->get('/auth/me', [AuthController::class, 'me']);
$router->post('/auth/logout', [AuthController::class, 'logout']);
$router->post('/profile', [AuthController::class, 'updateProfile']);
$router->put('/profile/password', [AuthController::class, 'updatePassword']);

// ============================================
// Protected Routes (Authentication Required)
// ============================================

// Websites
$router->get('/websites', [WebsiteController::class, 'index']);
$router->post('/websites', [WebsiteController::class, 'store']);
$router->get('/websites/{id}', [WebsiteController::class, 'show']);
$router->put('/websites/{id}', [WebsiteController::class, 'update']);
$router->delete('/websites/{id}', [WebsiteController::class, 'destroy']);
$router->post('/websites/{id}/publish', [WebsiteController::class, 'publish']);

// Templates
$router->get('/templates', [WebsiteController::class, 'templates']);
$router->get('/templates/{id}', [WebsiteController::class, 'templateShow']);

// Social Accounts
$router->get('/social-accounts', [SocialController::class, 'index']);
$router->post('/social-accounts/connect', [SocialController::class, 'connect']);
$router->get('/social-accounts/callback', [SocialController::class, 'callback']);
$router->delete('/social-accounts/{id}', [SocialController::class, 'disconnect']);
$router->post('/social-accounts/{id}/sync', [SocialController::class, 'sync']);
$router->get('/social-accounts/{id}/profile', [SocialController::class, 'getProfile']);
$router->get('/social-accounts/{id}/projects', [SocialController::class, 'getProjects']);

// Stripe / Subscription
$router->get('/stripe/plans', [StripeController::class, 'getPlans']);
$router->post('/stripe/checkout', [StripeController::class, 'createCheckoutSession']);
$router->get('/stripe/subscription', [StripeController::class, 'getSubscription']);
$router->post('/stripe/cancel', [StripeController::class, 'cancelSubscription']);
$router->post('/stripe/webhook', [StripeController::class, 'webhook']);

// ============================================
// Admin Routes (Admin Only)
// ============================================

$router->get('/admin/users', function($data) {
    Auth::requireAdmin();
    $userModel = new User();

    $page = isset($data['page']) ? (int)$data['page'] : 1;
    $perPage = isset($data['per_page']) ? (int)$data['per_page'] : 10;
    $search = $data['search'] ?? '';

    $result = $userModel->getNonAdminUsers($page, $perPage, $search);
    Response::success($result);
});

$router->get('/admin/dashboard', function($data) {
    Auth::requireAdmin();

    $year = isset($data['year']) ? (int)$data['year'] : (int) date('Y');

    $userModel = new User();
    $websiteModel = new Website();
    $templateModel = new Template();

    // Get total counts
    $totalUsers = $userModel->getTotalNonAdminUsers();
    $totalWebsites = $websiteModel->count(['is_published' => true]);
    $totalTemplates = $templateModel->count(['is_active' => true]);

    // Get social accounts count
    $socialAccounts = Database::query(
        "SELECT COUNT(*) as total FROM social_accounts WHERE is_connected = TRUE"
    );
    $totalConnectedAccounts = (int) ($socialAccounts[0]['total'] ?? 0);

    // Get monthly registrations and website creations
    $monthlyRegistrations = $userModel->getMonthlyRegistrations($year);
    $monthlyWebsites = $websiteModel->getMonthlyCreations($year);


    Response::success([
        'total_users' => $totalUsers,
        'total_websites' => $totalWebsites,
        'total_connected_accounts' => $totalConnectedAccounts,
        'total_templates' => $totalTemplates,
        'monthly_registrations' => $monthlyRegistrations,
        'monthly_websites' => $monthlyWebsites,
        'year' => $year
    ]);
});

$router->put('/admin/users/{id}/toggle', function($data) {
    Auth::requireAdmin();
    $userModel = new User();

    $id = $data['id'] ?? null;
    if (!$id) {
        Response::error('User ID is required', 400);
    }

    $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
    $userModel->update((int)$id, ['is_active' => $isActive]);

    $message = $isActive ? 'User activated successfully' : 'User deactivated successfully';
    Response::success(null, $message);
});

$router->get('/admin/websites', function($data) {
    Auth::requireAdmin();
    $websites = Database::query(
        "SELECT w.*, u.email as owner_email
         FROM websites w
         JOIN users u ON w.user_id = u.id
         ORDER BY w.created_at DESC"
    );
    Response::success($websites);
});

$router->get('/admin/templates', function($data) {
    Auth::requireAdmin();
    $templateModel = new Template();

    $page = max(1, intval($data['page'] ?? 1));
    $perPage = min(100, max(1, intval($data['per_page'] ?? 12)));
    $offset = ($page - 1) * $perPage;
    $search = trim($data['search'] ?? '');

    $whereClause = '1=1';
    $params = [];

    if ($search !== '') {
        $whereClause .= ' AND name LIKE ?';
        $params[] = "%$search%";
    }

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM templates WHERE $whereClause";
    $totalResult = Database::query($countSql, $params);
    $total = $totalResult[0]['total'] ?? 0;

    // Get paginated results
    $sql = "SELECT t.*,
                   (SELECT COUNT(*) FROM websites WHERE template_id = t.id) as usage_count
            FROM templates t
            WHERE $whereClause
            ORDER BY t.created_at DESC
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
});

$router->post('/admin/templates', function($data) {
    Auth::requireAdmin();
    $templateModel = new Template();

    $required = ['name', 'html_template', 'css_styles'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            Response::error("Missing required field: $field", 400);
        }
    }

    $templateData = [
        'name' => $data['name'],
        'slug' => strtolower(str_replace(' ', '-', $data['name'])),
        'description' => $data['description'] ?? '',
        'category' => $data['category'] ?? 'portfolio',
        'html_template' => $data['html_template'],
        'css_styles' => $data['css_styles'],
        'is_premium' => isset($data['is_premium']) ? 1 : 0,
        'is_active' => 1,
    ];


    // Handle thumbnail upload
    $thumbnail_url = null;

    if (isset($data['_FILES']['thumbnail_url']) && $data['_FILES']['thumbnail_url']['error'] === UPLOAD_ERR_OK) {
        $file = $data['_FILES']['thumbnail_url'];
        $uploadDir = __DIR__ . '/../../public/uploads/thumbnails/';

        // Create directory if not exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('thumb_') . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
            $thumbnail_url = $protocol . '://' . $host . '/uploads/thumbnails/' . $filename;
        }
    }

    if ($thumbnail_url) {
        $templateData['thumbnail_url'] = $thumbnail_url;
    }

    $id = $templateModel->create($templateData);
    Response::created(['id' => $id]);
});

$router->put('/admin/templates/{id}', function($data) {
    Auth::requireAdmin();
    $templateModel = new Template();

    $id = $data['id'] ?? null;
    if (!$id) {
        Response::error('Template ID is required', 400);
    }

    error_log("PUT /admin/templates/{id} called with data keys: " . implode(', ', array_keys($data)));
    error_log("PUT /admin/templates/{id} _FILES: " . print_r($data['_FILES'] ?? [], true));

    $templateData = [];
    if (isset($data['name'])) $templateData['name'] = $data['name'];
    if (isset($data['description'])) $templateData['description'] = $data['description'];
    if (isset($data['category'])) $templateData['category'] = $data['category'];
    if (isset($data['html_template'])) $templateData['html_template'] = $data['html_template'];
    if (isset($data['css_styles'])) $templateData['css_styles'] = $data['css_styles'];
    if (isset($data['is_premium'])) $templateData['is_premium'] = $data['is_premium'] ? 1 : 0;
    if (isset($data['is_active'])) $templateData['is_active'] = $data['is_active'] ? 1 : 0;

    // Handle thumbnail upload
    if (isset($data['_FILES']['thumbnail_url']) && $data['_FILES']['thumbnail_url']['error'] === UPLOAD_ERR_OK) {
        $file = $data['_FILES']['thumbnail_url'];
        error_log("Thumbnail file found: " . print_r($file, true));

        $uploadDir = __DIR__ . '/../../public/uploads/thumbnails/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('thumb_') . '.' . $ext;
        $destination = $uploadDir . $filename;

        error_log("Upload destination: " . $destination);
        error_log("Temp file: " . $file['tmp_name']);
        error_log("File exists at temp: " . (file_exists($file['tmp_name']) ? 'yes' : 'no'));

        // Use copy/rename instead of move_uploaded_file for manually parsed PUT requests
        // move_uploaded_file only works for normal HTTP POST uploads
        if (file_exists($file['tmp_name'])) {
            if (copy($file['tmp_name'], $destination)) {
                unlink($file['tmp_name']); // Clean up temp file
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
                $templateData['thumbnail_url'] = $protocol . '://' . $host . '/uploads/thumbnails/' . $filename;
                error_log("Thumbnail uploaded successfully: " . $templateData['thumbnail_url']);
            } else {
                error_log("Thumbnail copy FAILED");
            }
        } else {
            error_log("Thumbnail temp file does not exist");
        }
    } else {
        error_log("Thumbnail upload condition not met or error: " . ($data['_FILES']['thumbnail_url']['error'] ?? 'not set'));
    }

    if (!empty($templateData)) {
        $templateModel->update((int)$id, $templateData);
    } else {
        error_log("No template data to update");
    }

    Response::success(['id' => $id], 'Template updated successfully');
});

$router->delete('/admin/templates/{id}', function($data) {
    Auth::requireAdmin();
    $templateModel = new Template();

    // Try to get ID from params first, then fallback to URI parsing
    $id = $data['params']['id'] ?? null;

    // If ID not in params, extract from REQUEST_URI
    if (!$id && isset($_SERVER['REQUEST_URI'])) {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (preg_match('/\/admin\/templates\/([0-9]+)/', $uri, $matches)) {
            $id = $matches[1];
        }
    }

    if (!$id) {
        Response::error('Template ID is required', 400);
    }

    $templateModel->delete((int)$id);
    Response::success(null, 'Template deleted successfully');
});
