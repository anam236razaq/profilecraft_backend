<?php
/**
 * Social Media Service
 *
 * Handles OAuth flows and API calls to social media platforms:
 * - LinkedIn
 * - GitHub
 * - Google (YouTube)
 */

class SocialService {
    private array $config;
    private string $baseUrl;

    public function __construct() {
        $this->baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8080';
        $this->config = [
            'github' => [
                'client_id' => $_ENV['GITHUB_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GITHUB_CLIENT_SECRET'] ?? '',
                'scope' => 'read:user,user:email,repo'
            ],
            'linkedin' => [
                            'client_id' => $_ENV['LINKEDIN_CLIENT_ID'] ?? '',
                            'client_secret' => $_ENV['LINKEDIN_CLIENT_SECRET'] ?? '',
                            'scope' => 'openid profile email'
                        ],
            'google' => [
                            'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                            'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                            'scope' => 'openid profile email'
                        ]
        ];
    }

    /**
     * Get OAuth authorization URL for a provider
     */
    public function getAuthorizationUrl(string $provider, string $state): string {
        $callbackUrl = $this->baseUrl . '/api/social-accounts/callback';

        return match($provider) {
            'github' => "https://github.com/login/oauth/authorize?client_id={$this->config['github']['client_id']}&redirect_uri=" . urlencode($callbackUrl) . "&scope={$this->config['github']['scope']}&state=$state",

            'linkedin' => "https://www.linkedin.com/oauth/v2/authorization?response_type=code&client_id={$this->config['linkedin']['client_id']}&redirect_uri=" . urlencode($callbackUrl) . "&scope={$this->config['linkedin']['scope']}&state=$state",

            'google' => "https://accounts.google.com/o/oauth2/v2/auth?response_type=code&client_id={$this->config['google']['client_id']}&redirect_uri=" . urlencode($callbackUrl) . "&scope={$this->config['google']['scope']}&state=$state&access_type=offline",

            default => throw new Exception("Unsupported provider: $provider")
        };
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $provider, string $code): ?array {
        $callbackUrl = $this->baseUrl . '/api/social-accounts/callback';

        return match($provider) {
            'github' => $this->githubExchangeToken($code, $callbackUrl),
            'linkedin' => $this->linkedinExchangeToken($code, $callbackUrl),
            'google' => $this->googleExchangeToken($code, $callbackUrl),
            default => null
        };
    }

    /**
     * Refresh expired access token
     */
    public function refreshAccessToken(string $provider, string $refreshToken): ?array {
        return match($provider) {
            'github' => null, // GitHub tokens don't expire by default
            'linkedin' => $this->linkedinRefreshToken($refreshToken),
            'google' => $this->googleRefreshToken($refreshToken),
            default => null
        };
    }

    /**
     * Revoke token (logout from provider)
     */
    public function revokeToken(string $provider, string $accessToken): void {
        match($provider) {
            'github' => $this->githubRevokeToken($accessToken),
            'linkedin' => null, // LinkedIn doesn't support token revocation
            'google' => null, // Google doesn't provide revocation
            default => null
        };
    }

    /**
     * Fetch user profile from provider
     */
    public function getProfile(string $provider, string $accessToken): ?array {
        return match($provider) {
            'github' => $this->githubGetProfile($accessToken),
            'linkedin' => $this->linkedinGetProfile($accessToken),
            'google' => $this->googleGetProfile($accessToken),
            default => null
        };
    }

    /**
     * Fetch posts/content from provider
     */
    public function getPosts(string $provider, string $accessToken, int $limit = 20): array {
        return match($provider) {
            'github' => [], // GitHub uses getRepos instead
            'linkedin' => $this->linkedinGetPosts($accessToken, $limit),
            'google' => $this->googleGetVideos($accessToken, $limit),
            default => []
        };
    }

    /**
     * Fetch GitHub repositories
     */
    public function getRepos(string $provider, string $accessToken, int $limit = 20): array {
        if ($provider !== 'github') return [];

        $response = $this->httpGet('https://api.github.com/user/repos?sort=updated&per_page=' . $limit, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/vnd.github.v3+json',
            'User-Agent: ProfileCraft'
        ]);

        if (!$response) return [];

        $repos = json_decode($response, true) ?? [];
        return array_map(function($repo) {
            return [
                'id' => (string) $repo['id'],
                'name' => $repo['name'],
                'description' => $repo['description'],
                'url' => $repo['html_url'],
                'language' => $repo['language'],
                'stargazers_count' => $repo['stargazers_count'],
                'forks_count' => $repo['forks_count'],
                'created_at' => $repo['created_at'],
                'updated_at' => $repo['updated_at']
            ];
        }, $repos);
    }

    // ==================== GITHUB ====================

    private function githubExchangeToken(string $code, string $callbackUrl): ?array {
        $response = $this->httpPost('https://github.com/login/oauth/access_token', [
            'Accept: application/json'
        ], [
            'client_id' => $this->config['github']['client_id'],
            'client_secret' => $this->config['github']['client_secret'],
            'code' => $code,
            'redirect_uri' => $callbackUrl
        ]);

        if (!$response) return null;

        $data = json_decode($response, true);
        if (isset($data['error'])) return null;

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => 0,
            'provider_user_id' => null
        ];
    }

    private function githubGetProfile(string $accessToken): ?array {
        $response = $this->httpGet('https://api.github.com/user', [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/vnd.github.v3+json',
            'User-Agent: ProfileCraft'
        ]);

        if (!$response) return null;

        $user = json_decode($response, true);
        if (isset($user['error'])) return null;

        return [
            'display_name' => $user['name'] ?? $user['login'],
            'username' => $user['login'],
            'bio' => $user['bio'],
            'profile_url' => $user['html_url'],
            'profile_image_url' => $user['avatar_url'],
            'location' => $user['location'],
            'website_url' => $user['blog'],
            'follower_count' => $user['followers'],
            'following_count' => $user['following'],
            'post_count' => $user['public_repos'],
            'metadata' => [
                'id' => (string) $user['id'],
                'company' => $user['company'],
                'twitter_username' => $user['twitter_username']
            ]
        ];
    }

    private function githubRevokeToken(string $accessToken): void {
        $this->httpDelete('https://api.github.com/applications/' . $this->config['github']['client_id'] . '/tokens/' . $accessToken, [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken
        ]);
    }

    // ==================== LINKEDIN ====================

    private function linkedinExchangeToken(string $code, string $callbackUrl): ?array {
            $response = $this->httpPost('https://www.linkedin.com/oauth/v2/accessToken', [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded'
            ], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callbackUrl,
                'client_id' => $this->config['linkedin']['client_id'],
                'client_secret' => $this->config['linkedin']['client_secret']
            ]);

            if (!$response) return null;

            $data = json_decode($response, true);
            if (isset($data['error'])) return null;

            // Decode id_token to get provider_user_id (LinkedIn member ID)
            $providerUserId = null;
            if (isset($data['id_token'])) {
                $parts = explode('.', $data['id_token']);
                if (count($parts) === 3) {
                    $payload = json_decode(base64_decode($parts[1]), true);
                    $providerUserId = $payload['sub'] ?? null;
                }
            }

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_in' => $data['expires_in'] ?? 3600,
                'provider_user_id' => $providerUserId
            ];
        }

    private function linkedinRefreshToken(string $refreshToken): ?array {
        $response = $this->httpPost('https://www.linkedin.com/oauth/v2/accessToken', [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'
        ], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->config['linkedin']['client_id'],
            'client_secret' => $this->config['linkedin']['client_secret']
        ]);

        if (!$response) return null;

        $data = json_decode($response, true);
        if (isset($data['error'])) return null;

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'] ?? 3600
        ];
    }

    private function linkedinGetProfile(string $accessToken): ?array {
            // Use OpenID Connect UserInfo endpoint for basic profile
            $response = $this->httpGet('https://api.linkedin.com/v2/userinfo', [
                'Authorization: Bearer ' . $accessToken
            ]);

            if (!$response) return null;

            $user = json_decode($response, true);
            if (isset($user['error'])) return null;

            // Start with userinfo data
            $displayName = $user['name'] ?? null;
            $username = $user['sub'] ?? null;
            $bio = $user['headline'] ?? null;
            $profileImageUrl = $user['picture'] ?? null;
            $location = null;
            $followerCount = 0;
            $followingCount = 0;

            // Try to get additional data from old API for connections
            try {
                $fullProfileResponse = $this->httpGet('https://api.linkedin.com/v2/me?projection=(id,firstName,lastName,headline,summary,industry,location,numConnections,numConnectionsCutoff)', [
                    'Authorization: Bearer ' . $accessToken
                ]);

                if ($fullProfileResponse) {
                    $fullProfile = json_decode($fullProfileResponse, true);
                    if ($fullProfile && !isset($fullProfile['error'])) {
                        // Get display name from firstName and lastName
                        $firstName = $fullProfile['firstName']['localized']['en_US'] ?? '';
                        $lastName = $fullProfile['lastName']['localized']['en_US'] ?? '';
                        if ($firstName || $lastName) {
                            $displayName = trim($firstName . ' ' . $lastName);
                        }

                        // Get bio/headline
                        if (isset($fullProfile['headline'])) {
                            $bio = $fullProfile['headline'];
                        }

                        // Get location - could be object with 'name' or just a string
                        if (isset($fullProfile['location'])) {
                            if (is_array($fullProfile['location']) && isset($fullProfile['location']['name'])) {
                                $location = $fullProfile['location']['name'];
                            } elseif (is_string($fullProfile['location'])) {
                                $location = $fullProfile['location'];
                            }
                        }

                        // Get follower count (numConnections)
                        if (isset($fullProfile['numConnections'])) {
                            $followerCount = (int) $fullProfile['numConnections'];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error fetching LinkedIn full profile: " . $e->getMessage());
            }

            return [
                'display_name' => $displayName,
                'username' => $username,
                'bio' => $bio,
                'profile_url' => $username ? 'https://www.linkedin.com/in/' . $username : null,
                'profile_image_url' => $profileImageUrl,
                'location' => $location,
                'website_url' => null,
                'follower_count' => $followerCount,
                'following_count' => $followingCount,
                'post_count' => 0,
                'metadata' => [
                    'email' => $user['email'] ?? null,
                    'email_verified' => $user['email_verified'] ?? false
                ]
            ];
        }

    private function linkedinGetPosts(string $accessToken, int $limit): array {
        $response = $this->httpGet('https://api.linkedin.com/v2/ugcPosts?count=' . $limit, [
            'Authorization: Bearer ' . $accessToken,
            'X-Restli-Protocol-Version: 2.0.0'
        ]);

        if (!$response) return [];

        $data = json_decode($response, true);
        $posts = $data['elements'] ?? [];

        return array_map(function($post) {
            return [
                'id' => $post['id'],
                'type' => 'text',
                'content' => $post['text']['text'] ?? null,
                'media_url' => null,
                'url' => null,
                'like_count' => 0,
                'comment_count' => 0,
                'share_count' => 0,
                'posted_at' => date('Y-m-d H:i:s', $post['created']['time'] / 1000)
            ];
        }, $posts);
    }

    // ==================== GOOGLE (YouTube) ====================

    private function googleExchangeToken(string $code, string $callbackUrl): ?array {
            $response = $this->httpPost('https://oauth2.googleapis.com/token', [
                'Content-Type: application/x-www-form-urlencoded'
            ], [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $this->config['google']['client_id'],
                'client_secret' => $this->config['google']['client_secret'],
                'redirect_uri' => $callbackUrl
            ]);

            if (!$response) return null;

            $data = json_decode($response, true);
            if (isset($data['error'])) return null;

            // Decode id_token to get provider_user_id
            $providerUserId = null;
            if (isset($data['id_token'])) {
                $parts = explode('.', $data['id_token']);
                if (count($parts) === 3) {
                    $payload = json_decode(base64_decode($parts[1]), true);
                    $providerUserId = $payload['sub'] ?? null;
                }
            }

            return [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_in' => $data['expires_in'] ?? 3600,
                'provider_user_id' => $providerUserId
            ];
        }

    private function googleRefreshToken(string $refreshToken): ?array {
        $response = $this->httpPost('https://oauth2.googleapis.com/token', [
            'Content-Type: application/x-www-form-urlencoded'
        ], [
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['google']['client_id'],
            'client_secret' => $this->config['google']['client_secret']
        ]);

        if (!$response) return null;

        $data = json_decode($response, true);
        if (isset($data['error'])) return null;

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'] ?? 3600
        ];
    }

    private function googleGetProfile(string $accessToken): ?array {
            // Use OpenID Connect UserInfo endpoint
            $response = $this->httpGet('https://openidconnect.googleapis.com/v1/userinfo', [
                'Authorization: Bearer ' . $accessToken
            ]);

            if (!$response) return null;

            $user = json_decode($response, true);
            if (isset($user['error'])) return null;

            return [
                'display_name' => $user['name'] ?? null,
                'username' => $user['sub'] ?? null,
                'bio' => $user['profile'] ?? null,
                'profile_url' => $user['profile'] ?? null,
                'profile_image_url' => $user['picture'] ?? null,
                'location' => $user['locale'] ?? null,
                'website_url' => null,
                'follower_count' => 0,
                'following_count' => 0,
                'post_count' => 0,
                'metadata' => [
                    'email' => $user['email'] ?? null,
                    'email_verified' => $user['email_verified'] ?? false
                ]
            ];
        }

    private function googleGetVideos(string $accessToken, int $limit): array {
        $playlistResponse = $this->httpGet('https://www.googleapis.com/youtube/v3/channels?part=contentDetails&mine=true', [
            'Authorization: Bearer ' . $accessToken
        ]);

        if (!$playlistResponse) return [];

        $playlistData = json_decode($playlistResponse, true);
        $uploadsPlaylistId = $playlistData['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if (!$uploadsPlaylistId) return [];

        $response = $this->httpGet('https://www.googleapis.com/youtube/v3/playlistItems?part=snippet,contentDetails&playlistId=' . $uploadsPlaylistId . '&maxResults=' . $limit, [
            'Authorization: Bearer ' . $accessToken
        ]);

        if (!$response) return [];

        $data = json_decode($response, true);
        $videos = $data['items'] ?? [];

        return array_map(function($video) {
            $snippet = $video['snippet'];
            return [
                'id' => $video['contentDetails']['videoId'],
                'type' => 'video',
                'content' => $snippet['description'],
                'media_url' => $snippet['thumbnails']['medium']['url'] ?? null,
                'url' => 'https://youtube.com/watch?v=' . $video['contentDetails']['videoId'],
                'like_count' => 0,
                'comment_count' => 0,
                'share_count' => 0,
                'posted_at' => $snippet['publishedAt']
            ];
        }, $videos);
    }

    // ==================== HTTP HELPERS ====================

    private function httpGet(string $url, array $headers = []): ?string {
        return $this->httpRequest('GET', $url, $headers);
    }

    private function httpPost(string $url, array $headers = [], array $body = []): ?string {
        return $this->httpRequest('POST', $url, $headers, $body);
    }

    private function httpDelete(string $url, array $headers = []): ?string {
        return $this->httpRequest('DELETE', $url, $headers);
    }

    private function httpRequest(string $method, string $url, array $headers = [], array $body = []): ?string {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            error_log("HTTP $method $url failed: $error (HTTP $httpCode)");
            return null;
        }

        return $response;
    }
}
