<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AgentCommissionSettingsTableSeeder extends Seeder
{

    /**
     * Auto generated seed file
     *
     * @return void
     */
    public function run()
    {
        

        \DB::table('agent_commission_settings')->delete();
        
        \DB::table('agent_commission_settings')->insert(array (
            0 =>
            array (
                'id' => 1,
                'customer_percent' => '5.00',
                'vendor_percent' => '10.00',
                'agent_percent' => '15.00',
                'max_vendor_rider_payout_commissions' => 5,
                'created_at' => '2026-04-12 19:46:30',
                'updated_at' => '2026-04-17 17:18:24',
            ),
        ));
        
        
    }
}