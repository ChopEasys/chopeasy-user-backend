<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GeoLocationService;
use Illuminate\Console\Command;

class BackfillAgentCoordinates extends Command
{
    protected $signature = 'agents:backfill-coordinates';
    protected $description = 'Geocode addresses for agents missing latitude/longitude';

    public function handle(GeoLocationService $geoService): int
    {
        $agents = User::where('user_type', 'agent')
            ->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            })
            ->whereNotNull('address')
            ->get();

        if ($agents->isEmpty()) {
            $this->info('No agents with missing coordinates found.');
            return 0;
        }

        $this->info("Found {$agents->count()} agent(s) with missing coordinates.");

        foreach ($agents as $agent) {
            try {
                [$lat, $lng] = $geoService->getCoordinatesFromAddress($agent->address);
                if ($lat && $lng) {
                    $agent->update(['latitude' => $lat, 'longitude' => $lng]);
                    $this->info("✓ {$agent->fullname} ({$agent->email}): {$lat}, {$lng}");
                } else {
                    $this->warn("✗ {$agent->fullname}: Could not geocode '{$agent->address}'");
                }
            } catch (\Throwable $e) {
                $this->error("✗ {$agent->fullname}: {$e->getMessage()}");
            }
        }

        $this->info('Done.');
        return 0;
    }
}
