<?php

namespace Tests\Unit;

use App\Models\AmbassadorPromotionRequest;
use App\Models\User;
use App\Services\AmbassadorPromotionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AmbassadorPromotionRejectionTest extends TestCase
{
    use RefreshDatabase;

    protected AmbassadorPromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmbassadorPromotionService();
    }

    #[Test]
    public function it_rejects_a_promotion_request_with_reason(): void
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
            'delivery_agent_tier' => 3,
            'ambassador_badge_tier' => null,
        ]);

        $admin = User::create([
            'fullname' => 'Admin User',
            'email' => 'admin@example.com',
            'phoneno' => '08098765432',
            'user_type' => 'admin',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $request = AmbassadorPromotionRequest::create([
            'agent_id' => $agent->id,
            'current_tier' => 3,
            'requested_tier' => 4,
            'status' => 'pending',
            'active_agents_snapshot' => 50,
            'subordinate_count_snapshot' => 5,
            'delivery_count_snapshot' => 200,
        ]);

        $reason = 'Insufficient leadership qualities demonstrated during evaluation period.';

        Carbon::setTestNow(Carbon::parse('2025-07-01 12:00:00'));

        $this->service->rejectPromotion($request, $admin, $reason);

        // Refresh from database
        $request->refresh();
        $agent->refresh();

        // Assert request status is rejected
        $this->assertEquals('rejected', $request->status);

        // Assert rejection reason is stored
        $this->assertEquals($reason, $request->rejection_reason);

        // Assert reviewed_by is set to admin's id
        $this->assertEquals($admin->id, $request->reviewed_by);

        // Assert reviewed_at is set
        $this->assertNotNull($request->reviewed_at);
        $this->assertEquals('2025-07-01 12:00:00', $request->reviewed_at->format('Y-m-d H:i:s'));

        // Assert agent's tier is NOT modified (stays at current tier)
        $this->assertEquals(3, $agent->delivery_agent_tier);
        $this->assertNull($agent->ambassador_badge_tier);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_does_not_modify_agent_tier_on_rejection(): void
    {
        $agent = User::create([
            'fullname' => 'Ambassador Agent',
            'email' => 'ambassador@example.com',
            'phoneno' => '08012345679',
            'user_type' => 'agent',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
            'delivery_agent_tier' => 4,
            'ambassador_badge_tier' => 4,
            'ambassador_promoted_at' => now()->subMonths(6),
        ]);

        $admin = User::create([
            'fullname' => 'Admin User',
            'email' => 'admin@example.com',
            'phoneno' => '08098765432',
            'user_type' => 'admin',
            'password' => bcrypt('password'),
            'is_verified' => true,
            'can_login' => true,
            'is_active' => true,
        ]);

        $request = AmbassadorPromotionRequest::create([
            'agent_id' => $agent->id,
            'current_tier' => 4,
            'requested_tier' => 5,
            'status' => 'pending',
            'active_agents_snapshot' => 80,
            'subordinate_count_snapshot' => 8,
            'delivery_count_snapshot' => 2500,
        ]);

        $this->service->rejectPromotion($request, $admin, 'Network growth rate below expectations.');

        $agent->refresh();

        // Agent should remain at tier 4 — no change
        $this->assertEquals(4, $agent->delivery_agent_tier);
        $this->assertEquals(4, $agent->ambassador_badge_tier);
    }
}
