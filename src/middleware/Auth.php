<?php
/**
 * Authentication Middleware
 *
 * Handles JWT token verification and user authentication.
 */

class Auth {
    private static string $secretKey;
    private static string $algorithm = 'HS256';

    /**
     * Initialize auth with secret key
     */
    public static function init(): void {
        self::$secretKey = $_ENV['APP_KEY'] ?? 'your-secret-key-change-in-production';
    }

    /**
     * Generate JWT token for user
     */
    public static function generateToken(array $user, int $expiresIn = 86400): string {
        self::init();

        $header = base64_encode(json_encode(['alg' => self::$algorithm, 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'iss' => $_ENV['APP_URL'] ?? 'localhost',
            'sub' => $user['id'],
            'email' => $user['email'],
            'iat' => time(),
            'exp' => time() + $expiresIn
        ]));

        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", self::$secretKey, true));

        return "$header.$payload.$signature";
    }

    /**
     * Verify JWT token and return payload
     */
    public static function verifyToken(string $token): ?array {
        self::init();

        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        // Verify signature
        $expectedSignature = base64_encode(hash_hmac('sha256', "$header.$payload", self::$secretKey, true));
        if (!hash_equals($expectedSignature, $signature)) return null;

        // Decode payload
        $decoded = json_decode(base64_decode($payload), true);
        if (!$decoded) return null;

        // Check expiration
        if (isset($decoded['exp']) && $decoded['exp'] < time()) return null;

        return $decoded;
    }

    /**
     * Get bearer token from Authorization header
     */
    public static function getBearerToken(): ?string {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Authenticate request - returns user or null
     */
    public static function authenticate(): ?array {
        $token = self::getBearerToken();
        if (!$token) return null;

        $payload = self::verifyToken($token);
        if (!$payload) return null;

        // Fetch user from database
        $user = Database::query(
            "SELECT id, email, full_name, avatar_url, bio, is_admin, plan FROM users WHERE id = ? AND is_active = TRUE",
            [$payload['sub']]
        );

        return $user[0] ?? null;
    }

    /**
     * Require authentication - exits if not authenticated
     */
    public static function requireAuth(): array {
        $user = self::authenticate();
        if (!$user) {
            Response::unauthorized('Authentication required');
        }
        return $user;
    }

    /**
     * Require admin role
     */
    public static function requireAdmin(): array {
        $user = self::requireAuth();
        if (!($user['is_admin'] ?? false)) {
            Response::forbidden('Admin access required');
        }
        return $user;
    }
}
