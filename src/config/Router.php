<?php
/**
 * Simple Router
 *
 * Handles URL routing for the API.
 */

class Router {
    private array $routes = [];
    private string $basePath = '/api';

    /**
     * Register a GET route
     */
    public function get(string $path, callable|array $handler): void {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, callable|array $handler): void {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, callable|array $handler): void {
        $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, callable|array $handler): void {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Add route to collection
     */
    private function addRoute(string $method, string $path, callable|array $handler): void {
        // Convert {id} to regex pattern using named capture groups
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            function($matches) {
                return '(?P<' . $matches[1] . '>[a-zA-Z0-9_]+)';
            },
            $path
        );
        $pattern = '#^' . $this->basePath . $pattern . '$#';

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    /**
     * Dispatch request to appropriate handler
     */
    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Debug: log request info
        error_log("Router dispatch: method=$method, uri=$uri");

        // Check content type for file uploads
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isMultipart = stripos($contentType, 'multipart/form-data') !== false;
        error_log("Content-Type: $contentType, IsMultipart: " . ($isMultipart ? 'true' : 'false'));

        $body = [];
        $files = [];

        if ($isMultipart) {
            // Handle multipart form data - for both POST and PUT
            // PHP doesn't auto-populate $_POST or $_FILES for PUT requests
            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                // For PUT multipart, parse php://input to get form fields AND files
                $input = file_get_contents('php://input');

                error_log("PUT multipart input length: " . strlen($input));
                error_log("PUT multipart content-type: " . $contentType);

                if (!empty($input)) {
                    // Parse multipart body manually
                    $boundary = '';
                    if (preg_match('/boundary=(.*)$/', $contentType, $matches)) {
                        $boundary = trim($matches[1], '"');
                    }
                    error_log("PUT multipart boundary: " . $boundary);

                    if ($boundary) {
                        $sections = explode('--' . $boundary, $input);
                        error_log("PUT multipart sections count: " . count($sections));

                        foreach ($sections as $sectionIdx => $section) {
                            $trimmedSection = trim($section);
                            error_log("Section $sectionIdx length: " . strlen($section) . ", trimmed: '" . $trimmedSection . "'");

                            if ($trimmedSection === '' || $trimmedSection === '--') continue;

                            // Split headers and content
                            $parts = explode("\r\n\r\n", $section, 2);
                            if (count($parts) < 2) continue;

                            $headers = $parts[0];
                            $content = rtrim($parts[1], "\r\n");

                            // Check if it's a file
                            if (stripos($headers, 'filename') !== false) {
                                // Extract field name and filename
                                if (preg_match('/name="([^"]+)"/', $headers, $nameMatch)) {
                                    $fieldName = $nameMatch[1];

                                    // Extract filename
                                    $filenameMatch = [];
                                    if (preg_match('/filename="([^"]+)"/', $headers, $filenameMatch)) {
                                        $filename = $filenameMatch[1];
                                        error_log("PUT file found: field=$fieldName, filename=$filename, contentSize=" . strlen($content));

                                        // Create temp file for the upload
                                        $tmpName = tempnam(sys_get_temp_dir(), 'upload_');
                                        $written = file_put_contents($tmpName, $content);
                                        error_log("PUT file written to temp: $tmpName, bytes: $written");

                                        $files[$fieldName] = [
                                            'name' => $filename,
                                            'type' => 'image/jpeg', // Can't reliably detect from raw content
                                            'tmp_name' => $tmpName,
                                            'error' => 0,
                                            'size' => strlen($content)
                                        ];
                                    }
                                }
                                continue;
                            }

                            // Regular form field
                            if (preg_match('/name="([^"]+)"/', $headers, $nameMatch)) {
                                $body[$nameMatch[1]] = $content;
                            }
                        }
                    }
                }
            } else {
                // POST multipart - standard PHP handling
                $body = $_POST;
                $files = $_FILES;
            }
        } else {
            // Handle JSON body
            $rawInput = file_get_contents('php://input');
            error_log("Raw input: " . ($rawInput ?: 'EMPTY'));
            $body = json_decode($rawInput, true) ?? [];
            error_log("Parsed body: " . print_r($body, true));
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            error_log("Checking route: " . $route['pattern'] . " against $uri");

            if (preg_match($route['pattern'], $uri, $matches)) {
                error_log("Route matched! Params: " . print_r($matches, true));
                // Extract route parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Merge body, files, and query params
                $request = array_merge($_GET, $body, $params);
                $request['_FILES'] = $files;
                error_log("Final request data: " . print_r($request, true));

                // Call handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    // [Controller::class, 'method']
                    [$controller, $method] = $handler;
                    error_log("Creating controller: $controller, calling method: $method");
                    $controller = new $controller();
                    error_log("Controller created, calling method with data: " . print_r($request, true));
                    $controller->$method($request);
                } else {
                    call_user_func($handler, $request);
                }
                return;
            }
        }

        // No route found - 404
        error_log("No route matched for $method $uri");
        Response::notFound('Endpoint not found');
    }
}
