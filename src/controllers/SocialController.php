<?php
/**
 * Social Accounts Controller
 * Handles OAuth connections to social media platforms
 */

require_once __DIR__ . '/../services/SocialService.php';

class SocialController {
    private SocialService $socialService;

    public function __construct() {
        $this->socialService = new SocialService();
    }

    /**
     * GET /api/social-accounts
     * List user's connected social accounts
     */
    public function index(array $data): void {
        $user = Auth::requireAuth();

        $accounts = Database::query(
            "SELECT sa.id, sa.provider, sa.provider_user_id, sa.provider_username, sa.provider_avatar_url, sa.is_connected, sa.last_fetched_at, sa.created_at,
                    sp.display_name, sp.bio, sp.profile_url, sp.follower_count, sp.following_count
             FROM social_accounts sa
             LEFT JOIN social_profiles sp ON sp.social_account_id = sa.id
             WHERE sa.user_id = ?
             ORDER BY sa.created_at DESC",
            [$user['id']]
        );

        Response::success($accounts);
    }

    /**
     * POST /api/social-accounts/connect
     * Initiate OAuth connection - returns auth URL to redirect to
     */
    public function connect(array $data): void {
        $user = Auth::requireAuth();

        if (empty($data['provider'])) {
            Response::validationError(['provider' => 'Provider is required']);
        }

        $validProviders = ['linkedin', 'github', 'google'];
        if (!in_array($data['provider'], $validProviders)) {
            Response::error('Invalid provider. Allowed: linkedin, github, google', 400);
        }

        // Check if already connected
        $existing = Database::query(
            "SELECT id, is_connected FROM social_accounts WHERE user_id = ? AND provider = ?",
            [$user['id'], $data['provider']]
        );

        if ($existing && $existing[0]['is_connected']) {
            Response::error('This account is already connected', 400);
        }

        // Encode user info in state parameter (base64 encoded JSON)
        $stateData = json_encode(['user_id' => $user['id'], 'provider' => $data['provider']]);
        $state = base64_encode($stateData);

        // Get auth URL from SocialService
        $authUrl = $this->socialService->getAuthorizationUrl($data['provider'], $state);

        Response::success([
            'auth_url' => $authUrl,
            'provider' => $data['provider'],
            'state' => $state
        ]);
    }

    /**
     * GET /api/social-accounts/callback
     * Handle OAuth callback from provider
     */
    public function callback(array $data): void {
        // Helper to return HTML error page
        $returnError = function(string $message) {
            echo '<!DOCTYPE html>';
            echo '<html><head><title>Authorization Failed</title>';
            echo '<style>body{font-family:Arial,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f0f0f0;}';
            echo '.card{background:white;padding:40px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);text-align:center;max-width:400px;}';
            echo '.error{color:#ef4444;}</style>';
            echo '</head><body>';
            echo '<div class="card">';
            echo '<h1 style="color:#ef4444;">✗ Authorization Failed</h1>';
            echo '<p>' . htmlspecialchars($message) . '</p>';
            echo '<p><a href="javascript:window.close()">Close this window</a></p>';
            echo '</div></body></html>';
            exit;
        };

        // Get data from GET params (GitHub and LinkedIn both use these)
        $code = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $error = $_GET['error'] ?? '';
        $errorDescription = $_GET['error_description'] ?? '';

        // Check if provider returned an error
        if (!empty($error)) {
            $returnError('Authorization denied: ' . ($errorDescription ?: $error));
        }

        if (empty($code)) {
            $returnError('Authorization code not received');
        }

        // Decode state parameter (contains user_id and provider)
        $stateData = null;
        $userId = null;
        $provider = null;

        if (!empty($state)) {
            $decoded = base64_decode($state, true);
            if ($decoded) {
                $stateData = json_decode($decoded, true);
            }
        }

        if ($stateData && isset($stateData['user_id'])) {
            $userId = $stateData['user_id'];
            $provider = $stateData['provider'] ?? 'github';
        }

        // If no user from state, require auth
        if (!$userId) {
            try {
                $user = Auth::requireAuth();
                $userId = $user['id'];
            } catch (Exception $e) {
                $returnError('User must be logged in to connect social accounts');
            }
        }

        // Default provider to github if not set
        if (empty($provider)) {
            $provider = 'github';
        }

        try {
            // Exchange code for access token
            $tokenData = $this->socialService->exchangeCodeForToken($provider, $code);

            if (!$tokenData || empty($tokenData['access_token'])) {
                $returnError('Failed to obtain access token');
            }

            // CRITICAL: Check if this account is already connected to another user
            // Fallback chain: provider_user_id → provider_username → user_id (from provider)
            $providerUserId = $tokenData['provider_user_id'] ?? null;
            $providerUsername = null;

            if ($provider === 'github') {
                $ghProfile = $this->socialService->getProfile('github', $tokenData['access_token']);
                $providerUserId = $ghProfile['metadata']['id'] ?? null;
                $providerUsername = $ghProfile['username'] ?? null;
            } elseif ($provider === 'linkedin') {
                // linkedin stores sub in provider_user_id, username in provider_username
                // linkedinCheck already has providerUserId from id_token
                $liProfile = $this->socialService->getProfile('linkedin', $tokenData['access_token']);
                $providerUsername = $liProfile['username'] ?? null;
            } elseif ($provider === 'google') {
                $googleProfile = $this->socialService->getProfile('google', $tokenData['access_token']);
                $providerUsername = $googleProfile['username'] ?? null;
            }

            $duplicateOwner = null;
            if ($providerUserId) {
                $duplicateOwner = Database::query(
                    "SELECT user_id FROM social_accounts WHERE provider = ? AND provider_user_id = ?",
                    [$provider, $providerUserId]
                );
            }
            if (!$duplicateOwner && $providerUsername) {
                $duplicateOwner = Database::query(
                    "SELECT user_id FROM social_accounts WHERE provider = ? AND provider_username = ?",
                    [$provider, $providerUsername]
                );
            }
            if (!$duplicateOwner && $providerUserId) {
                // Last resort: check by provider_user_id even if empty string (old records)
                $duplicateOwner = Database::query(
                    "SELECT user_id FROM social_accounts WHERE provider = ? AND provider_user_id = ? AND provider_user_id != ''",
                    [$provider, $providerUserId]
                );
            }

            if ($duplicateOwner && (int)$duplicateOwner[0]['user_id'] !== (int)$userId) {
                $returnError('This ' . ucfirst($provider) . ' account is already connected to another profile. Please disconnect it from that profile first.');
            }

            // Store the social account
            $accountData = [
                'user_id' => $userId,
                'provider' => $provider,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'token_expires_at' => isset($tokenData['expires_in'])
                    ? date('Y-m-d H:i:s', time() + $tokenData['expires_in'])
                    : null,
                'is_connected' => true
            ];

            // Check if account exists (connected or previously connected)
            $existing = Database::query(
                "SELECT id, is_connected FROM social_accounts WHERE user_id = ? AND provider = ?",
                [$userId, $provider]
            );

            $accountId = 0;
            if ($existing) {
                // Update existing (reconnect case - is_connected was FALSE)
                Database::execute(
                    "UPDATE social_accounts SET access_token = ?, refresh_token = ?, token_expires_at = ?, is_connected = TRUE, provider_user_id = ? WHERE id = ?",
                    [$accountData['access_token'], $accountData['refresh_token'], $accountData['token_expires_at'], $tokenData['provider_user_id'] ?? '', $existing[0]['id']]
                );
                $accountId = $existing[0]['id'];
            } else {
                // Create new
                $accountId = Database::execute(
                    "INSERT INTO social_accounts (user_id, provider, provider_user_id, access_token, refresh_token, token_expires_at, is_connected) VALUES (?, ?, ?, ?, ?, ?, TRUE)",
                    [$userId, $provider, $tokenData['provider_user_id'] ?? '', $accountData['access_token'], $accountData['refresh_token'] ?? '', $accountData['token_expires_at'] ?? null]
                );
                $accountId = Database::lastInsertId();
            }

            // CRITICAL: Ensure provider is set correctly (Google OAuth doesn't return provider name)
            Database::execute(
                "UPDATE social_accounts SET provider = ? WHERE id = ?",
                [$provider, $accountId]
            );

            // Fetch and store profile data
            $this->fetchAndStoreProfile($accountId, $provider, $tokenData['access_token']);

            // Fetch and store posts/content
            $this->fetchAndStoreContent($accountId, $provider, $tokenData['access_token']);

            // Update last_fetched_at and provider (in case it wasn't set on insert)
            Database::execute(
                "UPDATE social_accounts SET last_fetched_at = NOW(), provider = ? WHERE id = ?",
                [$provider, $accountId]
            );

            // Return HTML page for successful callback (popup will display this)
            echo '<!DOCTYPE html>';
            echo '<html><head><title>Authorization Successful</title>';
            echo '<style>body{font-family:Arial,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f0f0f0;}';
            echo '.card{background:white;padding:40px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);text-align:center;}';
            echo '.success{color:#22c55e;}.btn{background:#2563eb;color:white;padding:10px 20px;border:none;border-radius:5px;cursor:pointer;text-decoration:none;display:inline-block;margin-top:20px;}</style>';
            echo '</head><body>';
            echo '<div class="card">';
            echo '<h1 style="color:#22c55e;">✓ Success!</h1>';
            echo '<p>Your ' . ucfirst($provider) . ' account has been connected.</p>';
            echo '<p>You can close this window and refresh the page.</p>';
            echo '<script>setTimeout(() => window.close(), 3000);</script>';
            echo '</div></body></html>';
            exit;

        } catch (Exception $e) {
            error_log("Social callback error: " . $e->getMessage());
            $returnError('Failed to connect account: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/social-accounts/{id}
     * Disconnect a social account
     */
    public function disconnect(array $data): void {
        $user = Auth::requireAuth();

        $id = $data['id'] ?? null;
        if (!$id) {
            Response::error('Account ID is required', 400);
        }

        $account = Database::query(
            "SELECT * FROM social_accounts WHERE id = ? AND user_id = ?",
            [$id, $user['id']]
        );

        if (!$account) {
            Response::notFound('Social account not found');
        }

        $provider = $account[0]['provider'];

        // Revoke token if possible
        try {
            $this->socialService->revokeToken($provider, $account[0]['access_token']);
        } catch (Exception $e) {
            // Continue even if revoke fails
            error_log("Token revoke failed: " . $e->getMessage());
        }

        // For LinkedIn and GitHub: soft delete (is_connected = FALSE)
        // For all other providers (including Google): hard delete
        if ($provider === 'linkedin' || $provider === 'github') {
            // Soft delete for LinkedIn/GitHub
            Database::execute(
                "UPDATE social_accounts SET is_connected = FALSE, access_token = '' WHERE id = ?",
                [$id]
            );
            // Delete related profile/post data
            Database::execute("DELETE FROM social_projects WHERE social_account_id = ?", [$id]);
            Database::execute("DELETE FROM social_posts WHERE social_account_id = ?", [$id]);
            Database::execute("DELETE FROM social_profiles WHERE social_account_id = ?", [$id]);
        } else {
            // Hard delete for Google and any other provider
            Database::execute("DELETE FROM social_projects WHERE social_account_id = ?", [$id]);
            Database::execute("DELETE FROM social_posts WHERE social_account_id = ?", [$id]);
            Database::execute("DELETE FROM social_profiles WHERE social_account_id = ?", [$id]);
            Database::execute("DELETE FROM social_accounts WHERE id = ?", [$id]);
        }

        Response::success(null, 'Social account disconnected');
    }

    /**
     * POST /api/social-accounts/{id}/sync
     * Sync data from social account
     */
    public function sync(array $data): void {
        $user = Auth::requireAuth();

        $id = $data['id'] ?? null;
        if (!$id) {
            Response::error('Account ID is required', 400);
        }

        $account = Database::query(
            "SELECT * FROM social_accounts WHERE id = ? AND user_id = ? AND is_connected = TRUE",
            [$id, $user['id']]
        );

        if (!$account) {
            Response::notFound('Social account not found or not connected');
        }

        $account = $account[0];

        try {
            // Refresh token if expired
            if ($account['token_expires_at'] && strtotime($account['token_expires_at']) < time()) {
                $tokenData = $this->socialService->refreshAccessToken(
                    $account['provider'],
                    $account['refresh_token'] ?? ''
                );
                if ($tokenData) {
                    Database::execute(
                        "UPDATE social_accounts SET access_token = ?, token_expires_at = ? WHERE id = ?",
                        [$tokenData['access_token'], date('Y-m-d H:i:s', time() + $tokenData['expires_in']), $id]
                    );
                    $account['access_token'] = $tokenData['access_token'];
                }
            }

            // Sync profile
            $this->fetchAndStoreProfile($id, $account['provider'], $account['access_token']);

            // Sync content
            $this->fetchAndStoreContent($id, $account['provider'], $account['access_token']);

            // Update last_fetched_at
            Database::execute(
                "UPDATE social_accounts SET last_fetched_at = NOW() WHERE id = ?",
                [$id]
            );

            Response::success(['synced_at' => date('Y-m-d H:i:s')]);

        } catch (Exception $e) {
            error_log("Sync error: " . $e->getMessage());
            Response::error('Sync failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/social-accounts/{id}/profile
     * Get full profile data for an account
     */
    public function getProfile(array $data): void {
        $user = Auth::requireAuth();

        $id = $data['id'] ?? null;
        if (!$id) {
            Response::error('Account ID is required', 400);
        }

        $account = Database::query(
            "SELECT sa.*, sp.* FROM social_accounts sa
             LEFT JOIN social_profiles sp ON sp.social_account_id = sa.id
             WHERE sa.id = ? AND sa.user_id = ?",
            [$id, $user['id']]
        );

        if (!$account) {
            Response::notFound('Social account not found');
        }

        // Decode metadata JSON if it exists
        if (isset($account[0]['metadata']) && is_string($account[0]['metadata'])) {
            $account[0]['metadata'] = json_decode($account[0]['metadata'], true) ?? [];
        }

        // Get posts/projects too
        $posts = Database::query(
            "SELECT * FROM social_posts WHERE social_account_id = ? ORDER BY posted_at DESC LIMIT 20",
            [$id]
        );

        $projects = Database::query(
            "SELECT * FROM social_projects WHERE social_account_id = ? ORDER BY stars_count DESC LIMIT 20",
            [$id]
        );

        Response::success([
            'account' => $account[0],
            'posts' => $posts,
            'projects' => $projects
        ]);
    }

    /**
     * GET /api/social-accounts/{id}/projects
     * Get projects (repos) for an account
     */
    public function getProjects(array $data): void {
        $user = Auth::requireAuth();

        $id = $data['id'] ?? null;
        if (!$id) {
            Response::error('Account ID is required', 400);
        }

        // Verify ownership
        $account = Database::query(
            "SELECT * FROM social_accounts WHERE id = ? AND user_id = ?",
            [$id, $user['id']]
        );

        if (!$account) {
            Response::notFound('Social account not found');
        }

        $projects = Database::query(
            "SELECT * FROM social_projects WHERE social_account_id = ? ORDER BY stars_count DESC LIMIT 50",
            [$id]
        );

        Response::success($projects);
    }

    /**
     * Fetch and store user profile
     */
    private function fetchAndStoreProfile(int $accountId, string $provider, string $accessToken): void {
        $profileData = $this->socialService->getProfile($provider, $accessToken);

        if (!$profileData) return;

        // Check if profile exists
        $existing = Database::query(
            "SELECT id FROM social_profiles WHERE social_account_id = ?",
            [$accountId]
        );

        $profile = [
            'display_name' => $profileData['display_name'] ?? null,
            'username' => $profileData['username'] ?? null,
            'bio' => $profileData['bio'] ?? null,
            'profile_url' => $profileData['profile_url'] ?? null,
            'profile_image_url' => $profileData['profile_image_url'] ?? null,
            'location' => $profileData['location'] ?? null,
            'website_url' => $profileData['website_url'] ?? null,
            'follower_count' => $profileData['follower_count'] ?? 0,
            'following_count' => $profileData['following_count'] ?? 0,
            'post_count' => $profileData['post_count'] ?? 0,
            'metadata' => json_encode($profileData['metadata'] ?? []),
            'fetched_at' => date('Y-m-d H:i:s')
        ];

        if ($existing) {
            $set = [];
            $values = [];
            foreach ($profile as $key => $value) {
                $set[] = "$key = ?";
                $values[] = $value;
            }
            $values[] = $accountId;
            Database::execute(
                "UPDATE social_profiles SET " . implode(', ', $set) . " WHERE social_account_id = ?",
                $values
            );
        } else {
            $columns = implode(', ', array_keys($profile));
            $placeholders = implode(', ', array_fill(0, count($profile), '?'));
            Database::execute(
                "INSERT INTO social_profiles (social_account_id, $columns) VALUES ($accountId, " . implode(', ', array_fill(0, count($profile), '?')) . ")",
                array_values($profile)
            );
        }

        // Update username/avatar on social_accounts too
        Database::execute(
            "UPDATE social_accounts SET provider_username = ?, provider_avatar_url = ?, provider = ? WHERE id = ?",
            [$profileData['username'] ?? null, $profileData['profile_image_url'] ?? null, $provider, $accountId]
        );
    }

    /**
     * Fetch and store posts/repos
     */
    private function fetchAndStoreContent(int $accountId, string $provider, string $accessToken): void {
        if ($provider === 'github') {
            // Fetch repos
            $repos = $this->socialService->getRepos($provider, $accessToken);
            foreach ($repos as $repo) {
                $existing = Database::query(
                    "SELECT id FROM social_projects WHERE social_account_id = ? AND project_id = ?",
                    [$accountId, $repo['id']]
                );

                $projectData = [
                    'title' => $repo['name'],
                    'description' => $repo['description'] ?? null,
                    'url' => $repo['url'],
                    'language' => $repo['language'] ?? null,
                    'stars_count' => $repo['stargazers_count'] ?? 0,
                    'forks_count' => $repo['forks_count'] ?? 0,
                    'created_at' => $repo['created_at'] ?? null,
                    'updated_at' => $repo['updated_at'] ?? null,
                ];

                if ($existing) {
                    $set = [];
                    $values = [];
                    foreach ($projectData as $key => $value) {
                        $set[] = "$key = ?";
                        $values[] = $value;
                    }
                    $values[] = $accountId;
                    $values[] = $repo['id'];
                    Database::execute(
                        "UPDATE social_projects SET " . implode(', ', $set) . " WHERE social_account_id = ? AND project_id = ?",
                        $values
                    );
                } else {
                    Database::execute(
                        "INSERT INTO social_projects (social_account_id, project_id, title, description, url, language, stars_count, forks_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$accountId, $repo['id'], $projectData['title'], $projectData['description'], $projectData['url'], $projectData['language'], $projectData['stars_count'], $projectData['forks_count'], $projectData['created_at'], $projectData['updated_at']]
                    );
                }
            }
        } else {
            // Fetch posts for other providers
            $posts = $this->socialService->getPosts($provider, $accessToken);
            foreach ($posts as $post) {
                $existing = Database::query(
                    "SELECT id FROM social_posts WHERE social_account_id = ? AND post_id = ?",
                    [$accountId, $post['id']]
                );

                $postData = [
                    'post_type' => $post['type'] ?? 'text',
                    'content' => $post['content'] ?? null,
                    'media_url' => $post['media_url'] ?? null,
                    'post_url' => $post['url'] ?? null,
                    'like_count' => $post['like_count'] ?? 0,
                    'comment_count' => $post['comment_count'] ?? 0,
                    'share_count' => $post['share_count'] ?? 0,
                    'posted_at' => $post['posted_at'] ?? null,
                ];

                if ($existing) {
                    $set = [];
                    $values = [];
                    foreach ($postData as $key => $value) {
                        $set[] = "$key = ?";
                        $values[] = $value;
                    }
                    $values[] = $accountId;
                    $values[] = $post['id'];
                    Database::execute(
                        "UPDATE social_posts SET " . implode(', ', $set) . " WHERE social_account_id = ? AND post_id = ?",
                        $values
                    );
                } else {
                    Database::execute(
                        "INSERT INTO social_posts (social_account_id, post_id, post_type, content, media_url, post_url, like_count, comment_count, share_count, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$accountId, $post['id'], $postData['post_type'], $postData['content'], $postData['media_url'], $postData['post_url'], $postData['like_count'], $postData['comment_count'], $postData['share_count'], $postData['posted_at']]
                    );
                }
            }
        }
    }
}
