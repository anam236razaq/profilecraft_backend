<?php
/**
 * JSON Response Helper
 *
 * Standardizes API responses.
 */

class Response {
    /**
     * Send success response
     */
    public static function success(mixed $data = null, string $message = null, int $code = 200): void {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        self::send($response, $code);
    }

    /**
     * Send created response (201)
     */
    public static function created(mixed $data = null): void {
        self::send([
            'success' => true,
            'data' => $data
        ], 201);
    }

    /**
     * Send error response
     */
    public static function error(string $message, int $code = 400, mixed $errors = null): void {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        self::send($response, $code);
    }

    /**
     * Send not found response (404)
     */
    public static function notFound(string $message = 'Resource not found'): void {
        self::error($message, 404);
    }

    /**
     * Send unauthorized response (401)
     */
    public static function unauthorized(string $message = 'Unauthorized'): void {
        self::error($message, 401);
    }

    /**
     * Send forbidden response (403)
     */
    public static function forbidden(string $message = 'Forbidden'): void {
        self::error($message, 403);
    }

    /**
     * Send validation error (422)
     */
    public static function validationError(array $errors): void {
        self::error('Validation failed', 422, $errors);
    }

    /**
     * Send JSON response with HTTP status code
     */
    private static function send(array $data, int $code): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
