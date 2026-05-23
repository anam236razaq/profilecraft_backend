<?php
/**
 * Stripe Controller
 *
 * Handles Stripe payment integration using direct HTTP calls.
 */

class StripeController {
    private string $secretKey;
    private string $baseUrl = 'https://api.stripe.com';

    public function __construct() {
        $this->secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($this->secretKey)) {
            throw new Exception('Stripe secret key not configured');
        }
    }

    /**
     * Get all plans/prices from Stripe
     * GET /api/stripe/plans
     */
    public function getPlans(array $data): void {
        try {
            $plans = [
                [
                    'id' => 'basic',
                    'name' => 'Basic',
                    'description' => 'Perfect to get started',
                    'price' => 0,
                    'price_id' => null,
                    'interval' => 'month',
                    'features' => [
                        '1 Website',
                        'Basic Templates',
                        '500MB Storage',
                        'Basic Analytics',
                        'Community Support'
                    ],
                    'limits' => [
                        'websites' => 1,
                        'storage_mb' => 500,
                        'premium_templates' => false,
                        'custom_domain' => false,
                        'remove_branding' => false
                    ]
                ],
                [
                    'id' => 'pro',
                    'name' => 'Pro',
                    'description' => 'Best for professionals',
                    'price' => 1500,
                    'price_id' => $_ENV['STRIPE_PRICE_PRO'] ?? 'price_pro',
                    'interval' => 'month',
                    'features' => [
                        '5 Websites',
                        'All Premium Templates',
                        '5GB Storage',
                        'Advanced Analytics',
                        'Custom Domain',
                        'Priority Email Support'
                    ],
                    'limits' => [
                        'websites' => 5,
                        'storage_mb' => 5000,
                        'premium_templates' => true,
                        'custom_domain' => true,
                        'remove_branding' => true
                    ]
                ],
                [
                    'id' => 'enterprise',
                    'name' => 'Enterprise',
                    'description' => 'For agencies and teams',
                    'price' => 4900,
                    'price_id' => $_ENV['STRIPE_PRICE_ENTERPRISE'] ?? 'price_enterprise',
                    'interval' => 'month',
                    'features' => [
                        'Unlimited Websites',
                        'All Premium Templates',
                        '50GB Storage',
                        'Advanced Analytics + Export',
                        'Custom Domain',
                        'Remove Branding',
                        'API Access',
                        'Team Members (10)',
                        'Dedicated Support'
                    ],
                    'limits' => [
                        'websites' => -1,
                        'storage_mb' => 50000,
                        'premium_templates' => true,
                        'custom_domain' => true,
                        'remove_branding' => true
                    ]
                ]
            ];

            Response::success(['plans' => $plans]);
        } catch (Exception $e) {
            error_log("Stripe getPlans error: " . $e->getMessage());
            Response::error('Failed to fetch plans', 500);
        }
    }

    /**
     * Create a Stripe Checkout session for subscription
     * POST /api/stripe/checkout
     */
    public function createCheckoutSession(array $data): void {
        try {
            $user = Auth::requireAuth();
            $userId = $user['id'];

            $planId = $data['plan_id'] ?? null;
            if (!$planId) {
                Response::error('Plan ID is required', 400);
            }

            $plans = $this->getPlanConfig();
            $plan = null;
            foreach ($plans as $p) {
                if ($p['id'] === $planId) {
                    $plan = $p;
                    break;
                }
            }

            if (!$plan) {
                Response::error('Invalid plan', 400);
            }

            if ($plan['price'] === 0) {
                Response::error('Free plan does not require payment', 400);
            }

            if (empty($plan['price_id']) || strpos($plan['price_id'], 'price_') !== 0) {
                Response::error('Plan price not configured. Please add valid Stripe Price IDs in .env', 500);
            }

            $baseUrl = $this->getBaseUrl();

            // Create Stripe Checkout Session using cURL
            $postData = http_build_query([
                'mode' => 'subscription',
                'success_url' => $baseUrl . '/pricing?success=true&plan=' . $planId,
                'cancel_url' => $baseUrl . '/pricing?canceled=true',
                'customer_email' => $user['email'],
                'line_items[0][price]' => $plan['price_id'],
                'line_items[0][quantity]' => 1,
                'metadata[user_id]' => (string) $userId,
                'metadata[plan_id]' => $planId
            ]);

            $response = $this->makeStripeRequest('POST', '/v1/checkout/sessions', $postData);

            if (isset($response['error'])) {
                Response::error('Stripe error: ' . ($response['error']['message'] ?? 'Failed to create checkout'), 500);
            }

            Response::success([
                'session_id' => $response['id'] ?? '',
                'url' => $response['url'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("Stripe checkout error: " . $e->getMessage());
            Response::error('Failed to create checkout session: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get user's current subscription status
     * GET /api/stripe/subscription
     */
    public function getSubscription(array $data): void {
        try {
            $user = Auth::requireAuth();
            $userId = $user['id'];

            $subscription = Database::query(
                "SELECT * FROM subscriptions WHERE user_id = ? AND status = 'active' AND cancel_at_period_end = FALSE ORDER BY created_at DESC LIMIT 1",
                [$userId]
            );

            if (empty($subscription)) {
                // No active subscription — use user's plan from users table
                Response::success([
                    'active' => false,
                    'plan' => $user['plan'] ?? 'basic',
                    'subscription' => null
                ]);
                return;
            }

            Response::success([
                'active' => true,
                'plan' => $subscription[0]['plan_id'] ?? $user['plan'] ?? 'pro',
                'subscription' => $subscription[0],
                'current_period_end' => $subscription[0]['current_period_end'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Stripe getSubscription error: " . $e->getMessage());
            Response::error('Failed to fetch subscription', 500);
        }
    }

    /**
     * Cancel subscription
     * POST /api/stripe/cancel
     */
    public function cancelSubscription(array $data): void {
        try {
            $user = Auth::requireAuth();

            $subscription = Database::query(
                "SELECT * FROM subscriptions WHERE user_id = ? AND status = 'active' AND cancel_at_period_end = FALSE ORDER BY created_at DESC LIMIT 1",
                [$user['id']]
            );

            if (empty($subscription)) {
                Response::error('No active subscription found', 400);
            }

            $currentSubscription = $subscription[0];
            $previousSubscription = Database::query(
                "SELECT * FROM subscriptions WHERE user_id = ? AND status = 'active' AND cancel_at_period_end = FALSE AND id != ? ORDER BY created_at ASC LIMIT 1",
                [$user['id'], $currentSubscription['id']]
            );

            if (!empty($currentSubscription['stripe_subscription_id'])) {
                $this->makeStripeRequest(
                    'POST',
                    '/v1/subscriptions/' . $currentSubscription['stripe_subscription_id'],
                    'cancel_at_period_end=true'
                );
            }

            Database::query(
                "UPDATE subscriptions SET cancel_at_period_end = TRUE, updated_at = NOW() WHERE id = ?",
                [$currentSubscription['id']]
            );

            $previousPlan = !empty($previousSubscription) ? $previousSubscription[0]['plan_id'] : 'basic';
            Database::query(
                "UPDATE users SET plan = ?, updated_at = NOW() WHERE id = ?",
                [$previousPlan, $user['id']]
            );

            Response::success(null, 'Subscription will be canceled at the end of the current period');
        } catch (Exception $e) {
            error_log("Stripe cancelSubscription error: " . $e->getMessage());
            Response::error('Failed to cancel subscription', 500);
        }
    }

    /**
     * Stripe webhook handler
     * POST /api/stripe/webhook
     */
    public function webhook(array $data): void {
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        try {
            // Verify webhook signature manually
            $elements = explode(',', $sigHeader);
            $timestamp = null;
            $signatures = [];

            foreach ($elements as $element) {
                $parts = explode('=', $element, 2);
                if (count($parts) === 2) {
                    if ($parts[0] === 't') {
                        $timestamp = $parts[1];
                    } elseif ($parts[0] === 'v1') {
                        $signatures[] = $parts[1];
                    }
                }
            }

            if (!$timestamp || empty($signatures)) {
                throw new Exception('Missing webhook signature');
            }

            // Compute expected signature
            $secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';
            if (empty($secret)) {
                throw new Exception('Webhook secret not configured');
            }

            $payloadWithTimestamp = $timestamp . '.' . $payload;
            $expectedSignature = hash_hmac('sha256', $payloadWithTimestamp, $secret);

            // Verify signature matches
            $bodyHashValid = false;
            foreach ($signatures as $sig) {
                if (hash_equals($expectedSignature, $sig)) {
                    $bodyHashValid = true;
                    break;
                }
            }

            if (!$bodyHashValid) {
                throw new Exception('Webhook signature verification failed');
            }

            $event = json_decode($payload, true);

            if (!$event || !isset($event['type'])) {
                throw new Exception('Invalid webhook payload');
            }

            switch ($event['type']) {
                case 'checkout.session.completed':
                    $session = $event['data']['object'] ?? [];
                    $this->handleCheckoutCompleted($session);
                    break;

                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $subscription = $event['data']['object'] ?? [];
                    if ($event['type'] === 'customer.subscription.updated') {
                        $this->handleSubscriptionUpdated($subscription);
                    } else {
                        $this->handleSubscriptionDeleted($subscription);
                    }
                    break;

                case 'invoice.payment_succeeded':
                case 'invoice.payment_failed':
                    // Handle payment events if needed
                    break;

                default:
                    error_log("Unhandled webhook event type: " . $event['type']);
            }

            Response::success(['received' => true]);
        } catch (Exception $e) {
            error_log("Webhook error: " . $e->getMessage());
            Response::error('Webhook failed: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Handle checkout session completed
     */
    private function handleCheckoutCompleted(array $session): void {
        $userId = $session['metadata']['user_id'] ?? null;
        $planId = $session['metadata']['plan_id'] ?? null;

        if (!$userId || !$planId) {
            error_log("Missing metadata in checkout session");
            return;
        }

        $stripeCustomerId = $session['customer'] ?? null;
        $stripeSubscriptionId = $session['subscription'] ?? null;

        $currentPeriodEnd = null;
        if ($stripeSubscriptionId) {
            $subResponse = $this->makeStripeRequest('GET', '/v1/subscriptions/' . $stripeSubscriptionId);
            if (isset($subResponse['current_period_end'])) {
                $currentPeriodEnd = date('Y-m-d H:i:s', $subResponse['current_period_end']);
            }
        }

        $existing = Database::query(
            "SELECT id FROM subscriptions WHERE stripe_subscription_id = ?",
            [$stripeSubscriptionId]
        );

        if (empty($existing)) {
            Database::query(
                "INSERT INTO subscriptions (user_id, plan_id, stripe_customer_id, stripe_subscription_id, status, current_period_end, created_at)
                 VALUES (?, ?, ?, ?, 'active', ?, NOW())",
                [$userId, $planId, $stripeCustomerId, $stripeSubscriptionId, $currentPeriodEnd]
            );
        }

        Database::query(
            "UPDATE users SET plan = ?, updated_at = NOW() WHERE id = ?",
            [$planId, $userId]
        );

        error_log("Subscription created for user $userId with plan $planId");
    }

    /**
     * Handle subscription updated
     */
    private function handleSubscriptionUpdated(array $subscription): void {
        $stripeSubId = $subscription['id'] ?? null;
        $status = $subscription['status'] ?? 'active';
        $currentPeriodEnd = isset($subscription['current_period_end'])
            ? date('Y-m-d H:i:s', $subscription['current_period_end'])
            : null;

        if ($stripeSubId && $currentPeriodEnd) {
            Database::query(
                "UPDATE subscriptions SET status = ?, current_period_end = ?, updated_at = NOW() WHERE stripe_subscription_id = ?",
                [$status, $currentPeriodEnd, $stripeSubId]
            );
        }
    }

    /**
     * Handle subscription deleted
     */
    private function handleSubscriptionDeleted(array $subscription): void {
        $stripeSubId = $subscription['id'] ?? null;

        if ($stripeSubId) {
            Database::query(
                "UPDATE subscriptions SET status = 'canceled', updated_at = NOW() WHERE stripe_subscription_id = ?",
                [$stripeSubId]
            );

            $sub = Database::query(
                "SELECT user_id FROM subscriptions WHERE stripe_subscription_id = ?",
                [$stripeSubId]
            );

            if (!empty($sub)) {
                Database::query(
                    "UPDATE users SET plan = 'basic', updated_at = NOW() WHERE id = ?",
                    [$sub[0]['user_id']]
                );
            }
        }
    }

    /**
     * Make request to Stripe API using cURL
     */
    private function makeStripeRequest(string $method, string $endpoint, ?string $postData = null): array {
        $ch = curl_init($this->baseUrl . $endpoint);

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/x-www-form-urlencoded'
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($postData) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            error_log("Stripe API error: " . $response);
            return $decoded ?? ['error' => ['message' => 'Stripe API error']];
        }

        return $decoded ?? [];
    }

    /**
     * Get base URL for redirect URLs
     */
    private function getBaseUrl(): string {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:5173';
        return rtrim($frontendUrl, '/');
    }

    /**
     * Get plan configuration
     */
    private function getPlanConfig(): array {
        return [
            [
                'id' => 'basic',
                'name' => 'Basic',
                'price' => 0,
                'price_id' => null
            ],
            [
                'id' => 'pro',
                'name' => 'Pro',
                'price' => 1500,
                'price_id' => $_ENV['STRIPE_PRICE_PRO'] ?? ''
            ],
            [
                'id' => 'enterprise',
                'name' => 'Enterprise',
                'price' => 4900,
                'price_id' => $_ENV['STRIPE_PRICE_ENTERPRISE'] ?? ''
            ]
        ];
    }
}