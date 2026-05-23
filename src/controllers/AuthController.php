<?php
/**
 * Authentication Controller
 */

class AuthController {
    private User $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    /**
     * POST /api/auth/register
     */
    public function register(array $data): void {
        // Validate required fields
        $required = ['email', 'password', 'full_name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                Response::validationError([$field => "The $field field is required"]);
            }
        }

        // Check if email already exists
        if ($this->userModel->findByEmail($data['email'])) {
            Response::error('Email already registered', 409);
        }

        // Create user
        $userId = $this->userModel->createWithPassword([
            'email' => $data['email'],
            'password' => $data['password'],
            'full_name' => $data['full_name'],
            'bio' => $data['bio'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
        ]);

        // Generate token (getSafeUser removes password_hash)
        $user = $this->userModel->getSafeUser($userId);
        $token = Auth::generateToken($user);

        Response::created([
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * POST /api/auth/login
     */
    public function login(array $data): void {
        if (empty($data['email']) || empty($data['password'])) {
            Response::validationError([
                'email' => empty($data['email']) ? 'Email is required' : null,
                'password' => empty($data['password']) ? 'Password is required' : null
            ]);
        }

        // First check if user exists
        $user = $this->userModel->findByEmail($data['email']);

        if (!$user) {
            Response::error('Invalid email or password', 401);
        }

        // Check if user is active
        if (!($user['is_active'] ?? true)) {
            Response::error('Your account has been deactivated', 403);
        }

        // Verify password
        $user = $this->userModel->verifyPassword($data['email'], $data['password']);

        if (!$user) {
            Response::error('Invalid email or password', 401);
        }

        // verifyPassword already removes password_hash
        $token = Auth::generateToken($user);

        Response::success([
            'user' => $user,
            'token' => $token
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(array $data): void {
        $user = Auth::requireAuth();
        unset($user['password_hash']); // Remove password_hash from response
        Response::success($user);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(array $data): void {
        // In a real app, you'd invalidate the token here
        Response::success(['message' => 'Logged out successfully']);
    }

    /**
     * PUT /api/profile
     * Update user profile (name, email, avatar)
     */
    public function updateProfile(array $data): void {
        $user = Auth::requireAuth();
        $userModel = new User();

        $updateData = [];

        // Update full_name if provided
        if (isset($data['full_name']) && !empty($data['full_name'])) {
            $updateData['full_name'] = trim($data['full_name']);
        }

        // Update email if provided
        if (isset($data['email']) && !empty($data['email'])) {
            $email = trim($data['email']);
            // Check if email is already taken by another user
            $existingUser = $userModel->findByEmail($email);
            if ($existingUser && $existingUser['id'] !== $user['id']) {
                Response::error('Email is already in use', 409);
            }
            $updateData['email'] = $email;
        }

        // Handle avatar upload
        if (isset($data['_FILES']['avatar']) && $data['_FILES']['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $data['_FILES']['avatar'];
            $uploadDir = __DIR__ . '/../../public/uploads/profile_images/';

            // Create directory if not exists
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                Response::error('Invalid image file. Allowed types: JPEG, PNG, GIF, WebP', 400);
            }

            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('avatar_') . '.' . $ext;
            $destination = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
                $updateData['avatar_url'] = $protocol . '://' . $host . '/uploads/profile_images/' . $filename;
            }
        }

        if (empty($updateData)) {
            Response::error('No data to update', 400);
        }

        $userModel->update($user['id'], $updateData);

        // Return updated user
        $updatedUser = $userModel->getSafeUser($user['id']);
        Response::success($updatedUser, 'Profile updated successfully');
    }

    /**
     * PUT /api/profile/password
     * Change user password
     */
    public function updatePassword(array $data): void {
        $user = Auth::requireAuth();
        $userModel = new User();

        if (empty($data['current_password']) || empty($data['new_password'])) {
            Response::validationError([
                'current_password' => empty($data['current_password']) ? 'Current password is required' : null,
                'new_password' => empty($data['new_password']) ? 'New password is required' : null
            ]);
        }

        // Verify current password
        $verifyUser = $userModel->verifyPassword($user['email'], $data['current_password']);
        if (!$verifyUser) {
            Response::error('Current password is incorrect', 401);
        }

        // Validate new password
        if (strlen($data['new_password']) < 6) {
            Response::validationError(['new_password' => 'Password must be at least 6 characters']);
        }

        // Update password
        $userModel->updatePassword($user['id'], $data['new_password']);

        Response::success(null, 'Password updated successfully');
    }
}
