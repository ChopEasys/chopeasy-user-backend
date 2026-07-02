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
 * Preservation Property Tests - Existing Customer/Vendor/Rider Referral and Commission Behavior
 *
 * These tests capture baseline behavior on UNFIXED code to ensure no regressions
 * are introduced when the agent downline fix is implemented.
 *
 * EXPECTED: These tests PASS on unfixed code (confirms baseline behavior to preserve).
 *
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6
 */
class AgentDownlinePreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure roles exist for Spatie Permission
        Role::firstOrCreate(['name' => 'Customer', 'guard_name' => 'web']);

        // Create commission settings with known values
        AgentCommissionSetting::create([
            'customer_percent' => 10,
            'vendor_percent' => 10,
            'agent_percent' => 10,
            'max_vendor_rider_payout_commissions' => 5,
        ]);
    }

    /**
     * Property: For all customer registrations with valid agent referral codes,
     * referred_by_agent_id is set to the referring agent's ID.
     *
     * **Validates: Requirements 3.1**
     */
    public function test_customer_registration_with_agent_referral_code_sets_referred_by_agent_id(): void
    {
        $agent = User::create([
            'fullname' => 'Referring Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'fullname' => 'Test Customer',
            'email' => 'customer@example.com',
            'phoneno' => '08098765432',
            'address' => '123 Lagos Street',
            'user_type' => 'customer',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => (string) $agent->id,
        ]);

        $response->assertStatus(201);

        $customer = User::where('email', 'customer@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertEquals($agent->id, $customer->referred_by_agent_id);
    }

    /**
     * Property: For all vendor registrations with valid agent referral codes,
     * referred_by_agent_id is set to the referring agent's ID.
     *
     * **Validates: Requirements 3.2**
     */
    public function test_vendor_registration_with_agent_referral_code_sets_referred_by_agent_id(): void
    {
        $agent = User::create([
            'fullname' => 'Referring Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'fullname' => 'Test Vendor',
            'email' => 'vendor@example.com',
            'phoneno' => '08098765433',
            'address' => '456 Vendor Avenue, Lagos',
            'user_type' => 'vendor',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => (string) $agent->id,
            'store_name' => 'Test Store',
            'bank_name' => 'Test Bank',
            'bank_code' => '058',
            'account_number' => '1234567890',
            'account_name' => 'Test Vendor',
        ]);

        $response->assertStatus(201);

        $vendor = User::where('email', 'vendor@example.com')->first();
        $this->assertNotNull($vendor);
        $this->assertEquals($agent->id, $vendor->referred_by_agent_id);
    }

    /**
     * Property: For all rider registrations with valid agent referral codes,
     * referred_by_agent_id is set to the referring agent's ID.
     *
     * **Validates: Requirements 3.3**
     */
    public function test_rider_registration_with_agent_referral_code_sets_referred_by_agent_id(): void
    {
        $agent = User::create([
            'fullname' => 'Referring Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'fullname' => 'Test Rider',
            'email' => 'rider@example.com',
            'phoneno' => '08098765434',
            'address' => '789 Rider Lane, Lagos',
            'user_type' => 'rider',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => (string) $agent->id,
            'vehicle' => 'motorcycle',
            'bank_name' => 'Test Bank',
            'bank_code' => '058',
            'account_number' => '0987654321',
            'account_name' => 'Test Rider',
        ]);

        $response->assertStatus(201);

        $rider = User::where('email', 'rider@example.com')->first();
        $this->assertNotNull($rider);
        $this->assertEquals($agent->id, $rider->referred_by_agent_id);
    }

    /**
     * Property: For all agents without referred_by_agent_id, earning full commission
     * produces no upline earning and no deduction. The agent receives 100% of the commission.
     *
     * **Validates: Requirements 3.4**
     */
    public function test_agent_without_upline_earns_full_commission_with_no_deduction(): void
    {
        // Agent with NO referred_by_agent_id (no upline)
        $agent = User::create([
            'fullname' => 'Solo Agent',
            'email' => 'solo@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'main_wallet' => 0,
            'referred_by_agent_id' => null,
        ]);

        // Customer referred by this agent
        $customer = User::create([
            'fullname' => 'Test Customer',
            'email' => 'customer@example.com',
            'phoneno' => '08098765432',
            'user_type' => 'customer',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'referred_by_agent_id' => $agent->id,
        ]);

        // Create an order from this customer with pricing breakdown
        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-TEST-001',
            'total_amount' => 10000,
            'customer_product_subtotal' => 8000,
            'service_fee_total' => 500,
            'delivery_fee_total' => 1500,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_type' => 'outright',
            'pricing_breakdown' => [
                'product_markup_total' => 2000,
                'service_fee_total' => 500,
            ],
        ]);

        $initialWallet = (float) $agent->main_wallet;

        // Credit the agent's commission for this customer order
        $commissionService = app(AgentCommissionService::class);
        $commissionService->creditCustomerOrderOnDeliveryConfirm($order);

        // Refresh agent's wallet
        $agent->refresh();

        // The agent should have earned the full commission amount
        $settings = AgentCommissionSetting::first();
        $expectedBase = 2000 + 500; // markup + service fee
        $expectedAmount = round($expectedBase * ($settings->customer_percent / 100), 2);

        $this->assertEquals(
            $initialWallet + $expectedAmount,
            (float) $agent->main_wallet,
            'Agent without upline should receive full commission with no deduction'
        );

        // Verify the earning record exists
        $earning = AgentEarning::where('agent_id', $agent->id)
            ->where('earning_type', 'customer_order')
            ->first();
        $this->assertNotNull($earning);
        $this->assertEquals($expectedAmount, (float) $earning->amount);

        // Verify NO agent_downline earning was created (since no upline exists)
        $uplineEarning = AgentEarning::where('earning_type', 'agent_downline')->first();
        $this->assertNull(
            $uplineEarning,
            'No agent_downline earning should exist when agent has no upline'
        );
    }

    /**
     * Property: For all customer commission calculations, amounts use existing
     * customer_percent setting unchanged.
     *
     * **Validates: Requirements 3.6**
     */
    public function test_customer_commission_uses_existing_customer_percent_setting(): void
    {
        // Update customer_percent to a specific test value
        $settings = AgentCommissionSetting::first();
        $settings->update(['customer_percent' => 12.50]);

        $agent = User::create([
            'fullname' => 'Commission Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'main_wallet' => 0,
        ]);

        $customer = User::create([
            'fullname' => 'Test Customer',
            'email' => 'customer@example.com',
            'phoneno' => '08098765432',
            'user_type' => 'customer',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'referred_by_agent_id' => $agent->id,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-TEST-002',
            'total_amount' => 15000,
            'customer_product_subtotal' => 12000,
            'service_fee_total' => 800,
            'delivery_fee_total' => 2200,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_type' => 'outright',
            'pricing_breakdown' => [
                'product_markup_total' => 3000,
                'service_fee_total' => 800,
            ],
        ]);

        $commissionService = app(AgentCommissionService::class);
        $commissionService->creditCustomerOrderOnDeliveryConfirm($order);

        $earning = AgentEarning::where('agent_id', $agent->id)
            ->where('earning_type', 'customer_order')
            ->first();

        $this->assertNotNull($earning);

        // Expected: (3000 + 800) * 12.50% = 475.00
        $expectedBase = 3000 + 800;
        $expectedAmount = round($expectedBase * (12.50 / 100), 2);

        $this->assertEquals(12.50, (float) $earning->commission_percent);
        $this->assertEquals($expectedAmount, (float) $earning->amount);
    }

    /**
     * Property: For all vendor commission calculations, amounts use existing
     * vendor_percent setting unchanged.
     *
     * **Validates: Requirements 3.6**
     */
    public function test_vendor_commission_uses_existing_vendor_percent_setting(): void
    {
        // Update vendor_percent to a specific test value
        $settings = AgentCommissionSetting::first();
        $settings->update(['vendor_percent' => 8.00]);

        $agent = User::create([
            'fullname' => 'Commission Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'main_wallet' => 0,
        ]);

        $vendor = User::create([
            'fullname' => 'Test Vendor',
            'email' => 'vendor@example.com',
            'phoneno' => '08098765433',
            'user_type' => 'vendor',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'referred_by_agent_id' => $agent->id,
        ]);

        $order = Order::create([
            'user_id' => $vendor->id,
            'order_number' => 'ORD-TEST-003',
            'total_amount' => 20000,
            'customer_product_subtotal' => 16000,
            'service_fee_total' => 1000,
            'delivery_fee_total' => 3000,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_type' => 'outright',
            'pricing_breakdown' => [
                'payout_breakdown' => [
                    'vendor_take_total' => 2500,
                ],
            ],
        ]);

        $vendorPayoutEntries = [
            [
                'vendor_id' => $vendor->id,
                'status' => 'paid',
                'vendor_take_amount' => 2500,
                'gross_amount' => 16000,
            ],
        ];

        $commissionService = app(AgentCommissionService::class);
        $commissionService->creditVendorReferralsAfterPayout($order, $vendorPayoutEntries);

        $earning = AgentEarning::where('agent_id', $agent->id)
            ->where('earning_type', 'vendor_payout')
            ->first();

        $this->assertNotNull($earning);

        // The vendor commission base uses vendor_take_total from payout_breakdown (2500)
        $expectedAmount = round(2500 * (8.00 / 100), 2);

        $this->assertEquals(8.00, (float) $earning->commission_percent);
        $this->assertEquals($expectedAmount, (float) $earning->amount);
    }

    /**
     * Property: For dashboard API responses, referrals.customer and referrals.vendor
     * blocks contain correct links and descriptions using existing percent settings.
     *
     * **Validates: Requirements 3.6**
     */
    public function test_dashboard_customer_and_vendor_referral_blocks_are_correct(): void
    {
        $agent = User::create([
            'fullname' => 'Dashboard Agent',
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

        // Verify customer referral block structure and content
        $customerRef = $data['referrals']['customer'];
        $this->assertNotNull($customerRef);
        $this->assertArrayHasKey('link', $customerRef);
        $this->assertArrayHasKey('title', $customerRef);
        $this->assertArrayHasKey('description', $customerRef);
        $this->assertStringContainsString('type=customer', $customerRef['link']);
        $this->assertStringContainsString('ref=' . $agent->id, $customerRef['link']);
        // The description contains the customer_percent value (10.00)
        $this->assertStringContainsString('10', $customerRef['description']);
        $this->assertStringContainsString('markup', $customerRef['description']);

        // Verify vendor referral block structure and content
        $vendorRef = $data['referrals']['vendor'];
        $this->assertNotNull($vendorRef);
        $this->assertArrayHasKey('link', $vendorRef);
        $this->assertArrayHasKey('title', $vendorRef);
        $this->assertArrayHasKey('description', $vendorRef);
        $this->assertStringContainsString('type=vendor', $vendorRef['link']);
        $this->assertStringContainsString('ref=' . $agent->id, $vendorRef['link']);
        $this->assertStringContainsString('10', $vendorRef['description']);
        $this->assertStringContainsString('vendor payout', $vendorRef['description']);
    }

    /**
     * Property: For multiple customer registrations with different valid agent referral codes,
     * each customer correctly links to its respective referring agent.
     *
     * **Validates: Requirements 3.1**
     */
    public function test_multiple_customers_correctly_linked_to_different_agents(): void
    {
        $agent1 = User::create([
            'fullname' => 'Agent One',
            'email' => 'agent1@example.com',
            'phoneno' => '08012345671',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $agent2 = User::create([
            'fullname' => 'Agent Two',
            'email' => 'agent2@example.com',
            'phoneno' => '08012345672',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        // Register customer 1 with agent 1's referral
        $this->postJson('/api/v1/auth/register', [
            'fullname' => 'Customer One',
            'email' => 'customer1@example.com',
            'phoneno' => '08098765001',
            'address' => '111 First Street',
            'user_type' => 'customer',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => (string) $agent1->id,
        ])->assertStatus(201);

        // Register customer 2 with agent 2's referral
        $this->postJson('/api/v1/auth/register', [
            'fullname' => 'Customer Two',
            'email' => 'customer2@example.com',
            'phoneno' => '08098765002',
            'address' => '222 Second Street',
            'user_type' => 'customer',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => (string) $agent2->id,
        ])->assertStatus(201);

        $customer1 = User::where('email', 'customer1@example.com')->first();
        $customer2 = User::where('email', 'customer2@example.com')->first();

        $this->assertEquals($agent1->id, $customer1->referred_by_agent_id);
        $this->assertEquals($agent2->id, $customer2->referred_by_agent_id);
    }

    /**
     * Property: Commission settings (customer_percent, vendor_percent) returned in
     * dashboard response match the actual database values.
     *
     * **Validates: Requirements 3.6**
     */
    public function test_dashboard_commission_settings_match_database_values(): void
    {
        // Set specific values
        $settings = AgentCommissionSetting::first();
        $settings->update([
            'customer_percent' => 15.00,
            'vendor_percent' => 12.00,
            'agent_percent' => 8.00,
            'max_vendor_rider_payout_commissions' => 3,
        ]);

        $agent = User::create([
            'fullname' => 'Settings Agent',
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
        $commissionSettings = $data['commission_settings'];

        $this->assertEquals(15.00, $commissionSettings['customer_percent']);
        $this->assertEquals(12.00, $commissionSettings['vendor_percent']);
        $this->assertEquals(3, $commissionSettings['max_vendor_rider_payout_commissions']);
    }

    /**
     * Property: Duplicate commission crediting is prevented - calling
     * creditCustomerOrderOnDeliveryConfirm twice for the same order does not
     * create duplicate earnings.
     *
     * **Validates: Requirements 3.6**
     */
    public function test_duplicate_commission_crediting_is_prevented(): void
    {
        $agent = User::create([
            'fullname' => 'Duplicate Test Agent',
            'email' => 'agent@example.com',
            'phoneno' => '08012345678',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'main_wallet' => 0,
        ]);

        $customer = User::create([
            'fullname' => 'Test Customer',
            'email' => 'customer@example.com',
            'phoneno' => '08098765432',
            'user_type' => 'customer',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'referred_by_agent_id' => $agent->id,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-DUP-001',
            'total_amount' => 10000,
            'customer_product_subtotal' => 8000,
            'service_fee_total' => 500,
            'delivery_fee_total' => 1500,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_type' => 'outright',
            'pricing_breakdown' => [
                'product_markup_total' => 2000,
                'service_fee_total' => 500,
            ],
        ]);

        $commissionService = app(AgentCommissionService::class);

        // Credit once
        $commissionService->creditCustomerOrderOnDeliveryConfirm($order);
        // Credit again (duplicate attempt)
        $commissionService->creditCustomerOrderOnDeliveryConfirm($order);

        // Should only have ONE earning record
        $earningCount = AgentEarning::where('agent_id', $agent->id)
            ->where('order_id', $order->id)
            ->where('earning_type', 'customer_order')
            ->count();

        $this->assertEquals(1, $earningCount, 'Only one earning should exist for the same order');
    }
}
