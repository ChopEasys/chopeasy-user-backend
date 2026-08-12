<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AmbassadorTierSeeder extends Seeder
{
    /**
     * Seed/update tier configurations for Tiers 1-7.
     *
     * Updates existing Tiers 1-3 with new ambassador branding names
     * and adds Tiers 4-7 with their commission rates, annual rewards,
     * min requirements, delivery windows, and territory scopes.
     *
     * Run with: php artisan db:seed --class=AmbassadorTierSeeder
     */
    public function run(): void
    {
        $this->command->info('🚀 Starting Ambassador Tier Seeder...');

        $tiers = [
            [
                'tier' => 1,
                'tier_name' => 'Active Agent',
                'max_order_amount' => 10000,
                'min_completed_deliveries' => 0,
                'min_security_deposit' => 0,
                'max_security_deposit' => 0,
                'description' => 'Entry level tier for new delivery agents',
                'active' => true,
                'commission_percent' => 10.00,
                'annual_reward_amount' => 0,
                'min_active_agents' => 0,
                'min_subordinate_tier' => 0,
                'subordinate_tier_level' => null,
                'min_deliveries' => 0,
                'delivery_window_months' => 12,
                'territory_scope' => 'none',
            ],
            [
                'tier' => 2,
                'tier_name' => 'Executive Agent',
                'max_order_amount' => 50000,
                'min_completed_deliveries' => 20,
                'min_security_deposit' => 20000,
                'max_security_deposit' => 20000,
                'description' => 'Intermediate tier for experienced agents',
                'active' => true,
                'commission_percent' => 10.00,
                'annual_reward_amount' => 0,
                'min_active_agents' => 0,
                'min_subordinate_tier' => 0,
                'subordinate_tier_level' => null,
                'min_deliveries' => 0,
                'delivery_window_months' => 12,
                'territory_scope' => 'none',
            ],
            [
                'tier' => 3,
                'tier_name' => 'Elite Agent',
                'max_order_amount' => 0,
                'min_completed_deliveries' => 100,
                'min_security_deposit' => 50000,
                'max_security_deposit' => 50000,
                'description' => 'Premium tier for high-performing agents',
                'active' => true,
                'commission_percent' => 10.00,
                'annual_reward_amount' => 0,
                'min_active_agents' => 0,
                'min_subordinate_tier' => 0,
                'subordinate_tier_level' => null,
                'min_deliveries' => 0,
                'delivery_window_months' => 12,
                'territory_scope' => 'none',
            ],
            [
                'tier' => 4,
                'tier_name' => 'Community Ambassador',
                'max_order_amount' => 0,
                'min_completed_deliveries' => 100,
                'min_security_deposit' => 50000,
                'max_security_deposit' => 50000,
                'description' => 'Community-level ambassador responsible for local area growth',
                'active' => true,
                'commission_percent' => 12.00,
                'annual_reward_amount' => 500000,
                'min_active_agents' => 70,
                'min_subordinate_tier' => 10,
                'subordinate_tier_level' => 3,
                'min_deliveries' => 350,
                'delivery_window_months' => 12,
                'territory_scope' => 'community',
            ],
            [
                'tier' => 5,
                'tier_name' => 'LGA Ambassador',
                'max_order_amount' => 0,
                'min_completed_deliveries' => 100,
                'min_security_deposit' => 50000,
                'max_security_deposit' => 50000,
                'description' => 'Local Government Area ambassador responsible for LGA-wide growth',
                'active' => true,
                'commission_percent' => 15.00,
                'annual_reward_amount' => 800000,
                'min_active_agents' => 100,
                'min_subordinate_tier' => 10,
                'subordinate_tier_level' => 4,
                'min_deliveries' => 3000,
                'delivery_window_months' => 12,
                'territory_scope' => 'lga',
            ],
            [
                'tier' => 6,
                'tier_name' => 'State Ambassador',
                'max_order_amount' => 0,
                'min_completed_deliveries' => 100,
                'min_security_deposit' => 50000,
                'max_security_deposit' => 50000,
                'description' => 'State-level ambassador responsible for statewide growth',
                'active' => true,
                'commission_percent' => 18.00,
                'annual_reward_amount' => 1500000,
                'min_active_agents' => 200,
                'min_subordinate_tier' => 3,
                'subordinate_tier_level' => 5,
                'min_deliveries' => 6000,
                'delivery_window_months' => 12,
                'territory_scope' => 'state',
            ],
            [
                'tier' => 7,
                'tier_name' => 'National Ambassador',
                'max_order_amount' => 0,
                'min_completed_deliveries' => 100,
                'min_security_deposit' => 50000,
                'max_security_deposit' => 50000,
                'description' => 'National-level ambassador responsible for nationwide growth',
                'active' => true,
                'commission_percent' => 20.00,
                'annual_reward_amount' => 3000000,
                'min_active_agents' => 500,
                'min_subordinate_tier' => 10,
                'subordinate_tier_level' => 6,
                'min_deliveries' => 15000,
                'delivery_window_months' => 12,
                'territory_scope' => 'national',
            ],
        ];

        foreach ($tiers as $tierData) {
            $tier = $tierData['tier'];
            $exists = DB::table('delivery_tier_settings')->where('tier', $tier)->exists();

            if ($exists) {
                DB::table('delivery_tier_settings')
                    ->where('tier', $tier)
                    ->update(array_merge($tierData, [
                        'updated_at' => now(),
                    ]));
                $this->command->line("  ✓ Tier {$tier}: {$tierData['tier_name']} (updated)");
            } else {
                DB::table('delivery_tier_settings')->insert(array_merge($tierData, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $this->command->line("  ✓ Tier {$tier}: {$tierData['tier_name']} (created)");
            }
        }

        $this->command->newLine();
        $this->command->info('✅ Ambassador Tier Seeder completed successfully!');
        $this->displaySummary();
    }

    /**
     * Display summary of seeded tier configurations
     */
    private function displaySummary(): void
    {
        $this->command->newLine();
        $this->command->info('📋 TIER CONFIGURATION SUMMARY:');
        $this->command->line('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $tiers = DB::table('delivery_tier_settings')->orderBy('tier')->get();

        foreach ($tiers as $tier) {
            $reward = $tier->annual_reward_amount > 0
                ? '₦' . number_format($tier->annual_reward_amount, 2)
                : 'None';

            $this->command->line("  Tier {$tier->tier}: {$tier->tier_name}");
            $this->command->line("    - Commission: {$tier->commission_percent}%");
            $this->command->line("    - Annual Reward: {$reward}");
            $this->command->line("    - Territory Scope: {$tier->territory_scope}");

            if ($tier->min_active_agents > 0) {
                $this->command->line("    - Min Active Agents: {$tier->min_active_agents}");
                $this->command->line("    - Min Subordinate Tier: {$tier->min_subordinate_tier} at Level {$tier->subordinate_tier_level}");
                $this->command->line("    - Min Deliveries: {$tier->min_deliveries} in {$tier->delivery_window_months} months");
            }

            $this->command->line('');
        }

        $this->command->line('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
