<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\DeliveryTier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RiderAssignmentService
{
    public function assignNearestRider(Order $order, bool $forceReassign = false): ?User
    {
        if (!$order->isPaidForFulfillment()) {
            return null;
        }

        if ($order->accepted_by && !$forceReassign) {
            return User::find($order->accepted_by);
        }

        [$pickupLat, $pickupLng] = $this->resolvePickupCoordinates($order);
        if (is_null($pickupLat) || is_null($pickupLng) || !$this->isValidCoordinates($pickupLat, $pickupLng)) {
            return null;
        }

        $radiusKm = (float) config('services.google_maps.rider_assignment_radius_km', 12);
        $orderAmount = $order->fulfillmentAmount();

        $agents = User::query()
            ->where('user_type', 'agent')
            ->where('is_delivery_agent', true)
            ->where('can_login', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'fullname', 'latitude', 'longitude', 'delivery_tier']);

        if ($agents->isEmpty()) {
            return null;
        }

        // Drop agents whose tier can't cover this order's amount before
        // doing any distance math — no point offering a ₦50k order to a
        // Tier 1 agent who can't legally accept it.
        $eligibleAgents = $agents->filter(function (User $agent) use ($orderAmount) {
            return DeliveryTier::tierCanHandle((int) ($agent->delivery_tier ?? 1), $orderAmount);
        });

        if ($eligibleAgents->isEmpty()) {
            return null;
        }

        $candidates = $eligibleAgents
            ->map(function (User $agent) use ($pickupLat, $pickupLng) {
                $agentLat = (float) $agent->latitude;
                $agentLng = (float) $agent->longitude;

                if (!$this->isValidCoordinates($agentLat, $agentLng)) {
                    return null;
                }

                $distanceKm = $this->haversineDistanceKm(
                    $agentLat,
                    $agentLng,
                    $pickupLat,
                    $pickupLng
                );

                return [
                    'rider' => $agent,
                    'distance_km' => $distanceKm,
                ];
            })
            ->filter()
            ->sortBy('distance_km')
            ->values();

        $withinRadius = $candidates
            ->filter(fn(array $row) => $row['distance_km'] <= $radiusKm)
            ->values();

        if ($withinRadius->isEmpty()) {
            return null;
        }

        $selected = $this->pickByRoadDistance($withinRadius, $pickupLat, $pickupLng, $radiusKm, $order->id);
        if (!$selected) {
            $selected = $withinRadius->first();
        }

        /** @var User $agent */
        $agent = $selected['rider'];
        $order->accepted_by = $agent->id;
        $order->save();

        return $agent;
    }

    private function resolvePickupCoordinates(Order $order): array
    {
        $order->loadMissing('vendorOrders.vendor');

        foreach ($order->vendorOrders as $vendorOrder) {
            $vendorLat = $vendorOrder->vendor?->latitude;
            $vendorLng = $vendorOrder->vendor?->longitude;

            if (is_null($vendorLat) || is_null($vendorLng)) {
                continue;
            }

            $lat = (float) $vendorLat;
            $lng = (float) $vendorLng;

            if (!$this->isValidCoordinates($lat, $lng)) {
                continue;
            }

            if ((string) $order->pickup_latitude !== (string) $vendorLat || (string) $order->pickup_longitude !== (string) $vendorLng) {
                $order->pickup_latitude = $lat;
                $order->pickup_longitude = $lng;
                $order->save();
            }

            return [$lat, $lng];
        }

        $lat = $order->pickup_latitude ? (float) $order->pickup_latitude : null;
        $lng = $order->pickup_longitude ? (float) $order->pickup_longitude : null;

        if (!is_null($lat) && !is_null($lng) && $this->isValidCoordinates($lat, $lng)) {
            return [$lat, $lng];
        }

        return $this->resolveDeliveryCoordinates($order);
    }

    private function resolveDeliveryCoordinates(Order $order): array
    {
        $lat = $order->delivery_latitude ? (float) $order->delivery_latitude : null;
        $lng = $order->delivery_longitude ? (float) $order->delivery_longitude : null;

        if (!is_null($lat) && !is_null($lng)) {
            return [$lat, $lng];
        }

        $order->loadMissing('user');
        $userLat = $order->user?->latitude;
        $userLng = $order->user?->longitude;

        if (is_null($userLat) || is_null($userLng)) {
            return [null, null];
        }

        $order->delivery_latitude = $userLat;
        $order->delivery_longitude = $userLng;
        $order->save();

        return [(float) $userLat, (float) $userLng];
    }

    private function pickByRoadDistance($candidates, float $deliveryLat, float $deliveryLng, float $radiusKm, int $orderId): ?array
    {
        $apiKey = config('services.google_maps.api_key');
        if (!$apiKey) {
            return null;
        }

        // Google Distance Matrix allows up to 25 origins per request.
        $origins = $candidates
            ->take(25)
            ->map(function (array $row) {
                /** @var User $rider */
                $rider = $row['rider'];
                return "{$rider->latitude},{$rider->longitude}";
            })
            ->implode('|');

        try {
            $response = Http::timeout(12)->get(
                'https://maps.googleapis.com/maps/api/distancematrix/json',
                [
                    'origins' => $origins,
                    'destinations' => "{$deliveryLat},{$deliveryLng}",
                    'mode' => 'driving',
                    'units' => 'metric',
                    'key' => $apiKey,
                ]
            );

            if (!$response->successful()) {
                return null;
            }

            $rows = $response->json('rows', []);
            $best = null;

            foreach ($rows as $index => $row) {
                $element = $row['elements'][0] ?? null;
                if (!$element || ($element['status'] ?? null) !== 'OK') {
                    continue;
                }

                $distanceMeters = $element['distance']['value'] ?? null;
                if (is_null($distanceMeters)) {
                    continue;
                }

                $distanceKm = ((float) $distanceMeters) / 1000;
                if ($distanceKm > $radiusKm) {
                    continue;
                }

                $candidate = $candidates[$index] ?? null;
                if (!$candidate) {
                    continue;
                }

                if (is_null($best) || $distanceKm < $best['road_distance_km']) {
                    $best = [
                        'rider' => $candidate['rider'],
                        'distance_km' => $candidate['distance_km'],
                        'road_distance_km' => $distanceKm,
                    ];
                }
            }

            return $best;
        } catch (\Throwable $e) {
            // Log::warning('Google Distance Matrix failed for rider assignment', [
            //     'order_id' => $orderId,
            //     'error' => $e->getMessage(),
            // ]);

            return null;
        }
    }

    private function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function isValidCoordinates(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }
}