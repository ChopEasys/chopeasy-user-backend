<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class PostalCodeController extends Controller
{
    /**
     * Validate full address via Postcodes.io
     */
    public function validateFullAddress(Request $request)
    {
        $request->validate([
            'postcode' => 'required|string',
            'city' => 'required|string',
            'street' => 'nullable|string',
        ]);

        $postcode = $request->postcode;
        $city = strtolower(trim($request->city));
        $street = $request->street ? strtolower(trim($request->street)) : null;

        // Lookup postcode details
        $response = Http::get("https://api.postcodes.io/postcodes/{$postcode}");

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Error validating postcode'
            ], 500);
        }

        $result = $response->json();

        if (!isset($result['result'])) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Invalid postcode'
            ]);
        }

        $postcodeData = $result['result'];

        // Validate city/town
        $cityMatch = strtolower($postcodeData['admin_district']) === $city
            || strtolower($postcodeData['parish']) === $city
            || strtolower($postcodeData['region']) === $city;


        $streetMatch = true;
        if ($street) {
            $parliamentaryCode = $postcodeData['codes']['parliamentary'] ?? '';
            $streetMatch = str_contains(strtolower($parliamentaryCode), $street)
                || str_contains(strtolower($postcodeData['admin_district'] ?? ''), $street);
        }


        return response()->json([
            'success' => true,
            'valid' => $cityMatch && $streetMatch,
            'postcodeData' => $postcodeData,
        ]);
    }

    public function suggestAddress(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $query = $request->query('query');

        try {
            $response = Http::get('https://api.geoapify.com/v1/geocode/autocomplete', [
                'text'   => $query,
                // Bounding box locked to Lagos state only [lon_min,lat_min,lon_max,lat_max]
                'filter' => 'rect:2.7774,6.3573,3.9480,6.7021',
                'bias'   => 'proximity:3.3792,6.5244',
                'limit'  => 5,
                'lang'   => 'en',
                'apiKey' => config('services.geoapify.key'),
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success'     => false,
                    'suggestions' => [],
                    'message'     => 'Could not fetch address suggestions.',
                ], 500);
            }

            $features = $response->json('features') ?? [];

            if (empty($features)) {
                return response()->json([
                    'success'     => true,
                    'suggestions' => [],
                    'message'     => 'No Lagos addresses found. Try a more specific address.',
                ]);
            }

            $suggestions = collect($features)
                ->map(function ($feature) {
                    $props  = $feature['properties'] ?? [];
                    $coords = $feature['geometry']['coordinates'] ?? [null, null];

                    return [
                        'display_name' => $props['formatted']  ?? '',
                        'lat'          => $coords[1],
                        'lon'          => $coords[0],
                        'city'         => $props['city']     ?? $props['town']    ?? $props['village'] ?? null,
                        'state'        => $props['state']    ?? null,
                        'country'      => $props['country']  ?? null,
                        'postcode'     => $props['postcode'] ?? null,
                    ];
                })
                ->filter(fn($s) => !empty($s['display_name']))
                ->values();

            return response()->json([
                'success'     => true,
                'suggestions' => $suggestions,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success'     => false,
                'suggestions' => [],
                'message'     => 'Service unavailable. Please try again.',
            ], 500);
        }
    }

    public function checkCoverage(Request $request)
    {
        $request->validate([
            'lga'       => 'required|string',
            'country'   => 'nullable|string',
            'state'     => 'nullable|string',
        ]);
    
        $lga     = $request->lga;
        $state   = $request->state ?? 'Lagos';
        $country = $request->country ?? 'Nigeria';
    
        // Geocode the LGA to get coordinates
        $geoResponse = Http::get('https://api.geoapify.com/v1/geocode/search', [
            'text'   => "{$lga}, {$state}, {$country}",
            'filter' => 'countrycode:ng',
            'limit'  => 1,
            'apiKey' => config('services.geoapify.key'),
        ])->json();
    
        $features = $geoResponse['features'] ?? [];
    
        if (empty($features)) {
            return response()->json([
                'success' => false,
                'covered' => false,
                'message' => 'Could not verify your location. Please try again.',
            ], 422);
        }
    
        $feature = $features[0];
    
        [$lon, $lat] = $feature['geometry']['coordinates'];
    
        $formattedAddress =
            $feature['properties']['formatted'] ?? "{$lga}, {$state}, {$country}";
    
        $radiusKm = 20;
    
        $vendor = DB::table('users')
            ->where('user_type', 'vendor')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("
                id,
                fullname,
                latitude,
                longitude,
                (
                    6371 * acos(
                        cos(radians(?)) * cos(radians(latitude))
                        * cos(radians(longitude) - radians(?))
                        + sin(radians(?)) * sin(radians(latitude))
                    )
                ) AS distance
            ", [$lat, $lon, $lat])
            ->having('distance', '<=', $radiusKm)
            ->orderBy('distance')
            ->first();
    
        if ($vendor) {
            return response()->json([
                'success' => true,
                'covered' => true,
    
                // Geocoded location
                'searched_location' => [
                    'lga'       => $lga,
                    'state'     => $state,
                    'country'   => $country,
                    'address'   => $formattedAddress,
                    'latitude'  => $lat,
                    'longitude' => $lon,
                ],
    
                // Vendor info
                'nearest_vendor' => [
                    'id'         => $vendor->id,
                    'name'       => $vendor->business_name ?? null,
                    'latitude'   => $vendor->latitude,
                    'longitude'  => $vendor->longitude,
                    'distance_km'=> round($vendor->distance, 1),
                ],
    
                'message' => "Great! We deliver to {$lga}, {$state}.",
            ]);
        }
    
        return response()->json([
            'success' => false,
            'covered' => false,
    
            // Still return searched coordinates
            'searched_location' => [
                'lga'       => $lga,
                'state'     => $state,
                'country'   => $country,
                'address'   => $formattedAddress,
                'latitude'  => $lat,
                'longitude' => $lon,
            ],
    
            'message' => "Sorry, we don't cover {$lga} yet. We're expanding soon!",
        ], 422);
    }
}
