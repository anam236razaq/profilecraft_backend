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

        $body = [];
        $files = [];

        // Check content type for file uploads
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

        if ($isMultipart) {
            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                // For PUT multipart, parse php://input to get form fields AND files
                $input = file_get_contents('php://input');

                if (!empty($input)) {
                    $boundary = '';
                    if (preg_match('/boundary=(.*)$/', $contentType, $matches)) {
                        $boundary = trim($matches[1], '"');
                    }

                    if ($boundary) {
                        $sections = explode('--' . $boundary, $input);

                        foreach ($sections as $section) {
                            $trimmedSection = trim($section);
                            if ($trimmedSection === '' || $trimmedSection === '--') continue;

                            // Split headers and content
                            $parts = explode("\r\n\r\n", $section, 2);
                            if (count($parts) < 2) continue;

                            $headers = $parts[0];
                            $content = rtrim($parts[1], "\r\n");

                            // Check if it's a file
                            if (stripos($headers, 'filename') !== false) {
                                if (preg_match('/name="([^"]+)"/', $headers, $nameMatch)) {
                                    $fieldName = $nameMatch[1];

                                    $filenameMatch = [];
                                    if (preg_match('/filename="([^"]+)"/', $headers, $filenameMatch)) {
                                        $filename = $filenameMatch[1];

                                        // Create temp file for the upload
                                        $tmpName = tempnam(sys_get_temp_dir(), 'upload_');
                                        file_put_contents($tmpName, $content);

                                        $files[$fieldName] = [
                                            'name' => $filename,
                                            'type' => 'image/jpeg',
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
            $body = json_decode($rawInput, true) ?? [];
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract route parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Merge body, files, and query params
                $request = array_merge($_GET, $body, $params);
                $request['_FILES'] = $files;

                // Call handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$controller, $method] = $handler;
                    $controller = new $controller();
                    $controller->$method($request);
                } else {
                    call_user_func($handler, $request);
                }
                return;
            }
        }

        // No route found - 404
        Response::notFound('Endpoint not found');
    }
}