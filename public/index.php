<?php
/**
 * ProfileCraft API - Entry Point
 *
 * All API requests are routed through this file.
 */

// CORS Headers - Allow any origin for development
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Start session for OAuth state management
session_start();

// Error reporting - Show errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// Define base path
define('BASE_PATH', __DIR__ . '/../');

// Load configurations
require_once BASE_PATH . 'src/config/Database.php';
require_once BASE_PATH . 'src/utils/Response.php';
require_once BASE_PATH . 'src/config/Router.php';
require_once BASE_PATH . 'src/middleware/Auth.php';
require_once BASE_PATH . 'src/models/Model.php';
require_once BASE_PATH . 'src/models/User.php';
require_once BASE_PATH . 'src/models/Website.php';
require_once BASE_PATH . 'src/models/Template.php';
require_once BASE_PATH . 'src/controllers/AuthController.php';
require_once BASE_PATH . 'src/controllers/WebsiteController.php';
require_once BASE_PATH . 'src/controllers/SocialController.php';
require_once BASE_PATH . 'src/controllers/StripeController.php';
require_once BASE_PATH . 'src/services/CloudinaryService.php';

// Initialize router as global
global $router;
$router = new Router();

// Load routes
require BASE_PATH . 'src/routes/api.php';

// Dispatch request
$router->dispatch();
