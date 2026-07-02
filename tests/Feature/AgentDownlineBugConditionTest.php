<?php

namespace Tests\Feature;

use App\Models\AgentCommissionSetting;
use App\Models\AgentEarning;
use App\Models\Order;
use App\Models\User;
use App\Services\AgentCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bug Condition Exploration Test - Agent Downline Referral and Earnings System
 *
 * This test exercises the four bug conditions identified in the bug analysis:
 * 1. Agent registration with referral code does not set referred_by_agent_id
 * 2. No upline earning is created when a downline agent earns
 * 3. Dashboard agent tab shows incorrect referral link and description
 * 4. No endpoint exists for viewing referred agents
 *
 * EXPECTED: These tests FAIL on unfixed code, confirming the bugs exist.
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4
 */
class AgentDownlineBugConditionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure roles exist for Spatie Permission
        Role::firstOrCreate(['name' => 'Customer', 'guard_name' => 'web']);

        // Create commission settings
        AgentCommissionSetting::create([
            'customer_percent' => 10,
            'vendor_percent' => 10,
            'agent_percent' => 10,
            'max_vendor_rider_payout_commissions' => 5,
        ]);
    }

    /**
     * Bug Condition 1: Agent registration with valid referral_code does not set referred_by_agent_id.
     *
     * The UserService.register() method has an in_array check that only allows
     * 'customer', 'vendor', 'rider' — excluding 'agent'.
     *
     * Expected: referred_by_agent_id should be set to the referring agent's ID
     * Actual (bug): referred_by_agent_id is NULL because agent type is excluded
     *
     * Validates: Requirements 1.1
     */
    public function test_agent_registration_with_referral_code_sets_referred_by_agent_id(): void
    {
        // Create the referring agent (upline)
        $uplineAgent = User::create([
            'fullname' => 'Upline Agent',
            'email' => 'upline@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        // Register a new agent with the upline's referral code
        $response = $this->postJson('/api/v1/auth/register', [
            'fullname' => 'Downline Agent',
            'email' => 'downline@example.com',
            'phoneno' => '08098765432',
            'address' => '123 Test Street, Lagos',
            'user_type' => 'agent',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => (string) $uplineAgent->id,
        ]);

        // The registration should succeed
        $response->assertStatus(201);

        // Assert referred_by_agent_id is set (this WILL FAIL on unfixed code)
        $downlineAgent = User::where('email', 'downline@example.com')->first();
        $this->assertNotNull($downlineAgent, 'Downline agent should exist after registration');
        $this->assertEquals(
            $uplineAgent->id,
            $downlineAgent->referred_by_agent_id,
            'referred_by_agent_id should be set to the upline agent ID when registering with a valid agent referral code'
        );
    }

    /**
     * Bug Condition 2: No upline earning of type agent_downline is created when downline earns.
     *
     * The AgentCommissionService has no method to credit an upline agent when their
     * downline agent earns a commission.
     *
     * Expected: An AgentEarning with earning_type='agent_downline' should be created for the upline
     * Actual (bug): No such earning exists because the logic doesn't exist
     *
     * Validates: Requirements 1.2
     */
    public function test_upline_earning_created_when_downline_agent_earns(): void
    {
        // Create upline agent
        $uplineAgent = User::create([
            'fullname' => 'Upline Agent',
            'email' => 'upline@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'main_wallet' => 0,
        ]);

        // Create downline agent with referred_by_agent_id set
        $downlineAgent = User::create([
            'fullname' => 'Downline Agent',
            'email' => 'downline@example.com',
            'phoneno' => '08098765432',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'referred_by_agent_id' => $uplineAgent->id,
            'main_wallet' => 0,
        ]);

        // Create a customer that was referred by the downline agent
        $customer = User::create([
            'fullname' => 'Test Customer',
            'email' => 'customer@example.com',
            'phoneno' => '08011111111',
            'user_type' => 'customer',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'referred_by_agent_id' => $downlineAgent->id,
        ]);

        // Create an order for the customer so the service method can process it
        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-TEST-001',
            'total_amount' => 5000,
            'customer_product_subtotal' => 4000,
            'service_fee_total' => 500,
            'delivery_fee_total' => 500,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_type' => 'outright',
            'pricing_breakdown' => json_encode([
                'product_markup_total' => 1000,
                'service_fee_total' => 500,
            ]),
        ]);

        // Use the service layer to credit the commission — this triggers the full flow
        // including creditUplineFromDownlineEarning()
        $service = app(AgentCommissionService::class);
        $service->creditCustomerOrderOnDeliveryConfirm($order);

        // Assert that an upline earning of type 'agent_downline' was created
        $uplineEarning = AgentEarning::where('agent_id', $uplineAgent->id)
            ->where('earning_type', 'agent_downline')
            ->first();

        $this->assertNotNull(
            $uplineEarning,
            'An AgentEarning with earning_type=agent_downline should be created for the upline agent when their downline earns'
        );
    }

    /**
     * Bug Condition 3a: Dashboard agent tab shows referral link using rider URL instead of agent URL.
     *
     * The AgentController.dashboard() uses config('app.register_base_rider') for the agent
     * referral link instead of a dedicated agent frontend URL.
     *
     * Expected: referrals.agent.link should use the agent frontend URL
     * Actual (bug): referrals.agent.link uses register_base_rider (rider domain)
     *
     * Validates: Requirements 1.3
     */
    public function test_dashboard_agent_referral_link_uses_agent_frontend_url(): void
    {
        $agent = User::create([
            'fullname' => 'Test Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $token = auth('api')->login($agent);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/agent/dashboard');

        $response->assertStatus(200);

        $data = $response->json('data');
        $agentLink = $data['referrals']['agent']['link'] ?? '';

        // The agent referral link should NOT use the rider base URL
        // It should use a dedicated agent frontend URL (e.g., agent.chopeasy.ng)
        $riderBase = config('app.register_base_rider', '');

        // Assert it does NOT use the rider base URL (WILL FAIL on unfixed code since it currently uses register_base_rider)
        $this->assertStringNotContainsString(
            rtrim($riderBase, '/'),
            $agentLink,
            'Agent referral link should NOT use the rider registration base URL. It should use a dedicated agent frontend URL.'
        );
    }

    /**
     * Bug Condition 3b: Dashboard agent tab description mentions "delivery profit" instead of downline commission.
     *
     * The agent referral description says "platform delivery profit (delivery fee minus agent payout)"
     * which describes rider referral earnings, not the downline agent earning model.
     *
     * Expected: Description should mention the downline commission percentage
     * Actual (bug): Description mentions "delivery profit" or "delivery fee minus agent payout"
     *
     * Validates: Requirements 1.3
     */
    public function test_dashboard_agent_referral_description_mentions_downline_commission(): void
    {
        $agent = User::create([
            'fullname' => 'Test Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $token = auth('api')->login($agent);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/agent/dashboard');

        $response->assertStatus(200);

        $data = $response->json('data');
        $description = $data['referrals']['agent']['description'] ?? '';

        // The description should NOT mention "delivery profit" — that's for rider referrals
        $this->assertStringNotContainsString(
            'delivery profit',
            $description,
            'Agent referral description should NOT mention "delivery profit". It should describe the downline commission percentage.'
        );

        // The description should mention commissions earned by referred agents
        $this->assertMatchesRegularExpression(
            '/commissions?\s+(earned\s+by\s+)?agents?\s+you\s+referred/i',
            $description,
            'Agent referral description should mention commissions from agents referred (downline earning model)'
        );
    }

    /**
     * Bug Condition 4: No endpoint exists for viewing referred (downline) agents.
     *
     * The AgentController has referredCustomers() but no referredAgents() method.
     * The route GET /agent/referred-agents does not exist.
     *
     * Expected: GET /agent/referred-agents returns 200 with downline agent list
     * Actual (bug): 404 because the endpoint does not exist
     *
     * Validates: Requirements 1.4
     */
    public function test_referred_agents_endpoint_returns_200(): void
    {
        $agent = User::create([
            'fullname' => 'Test Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $token = auth('api')->login($agent);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/agent/referred-agents');

        // This WILL FAIL on unfixed code because the endpoint doesn't exist (404)
        $response->assertStatus(200);
    }
}
