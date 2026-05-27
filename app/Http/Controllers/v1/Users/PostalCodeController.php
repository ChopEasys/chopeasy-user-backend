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

    /**
     * Hardcoded centroids for all 20 Lagos LGAs.
     * Using fixed coordinates avoids non-deterministic results from external
     * geocoding APIs, which was causing the same LGA to pass coverage for some
     * users and fail for others depending on which coordinate the API returned.
     */
    private array $lagosLgaCentroids = [
        'Agege'            => ['lat' =>  6.6212, 'lon' =>  3.3236],
        'Ajeromi-Ifelodun' => ['lat' =>  6.4698, 'lon' =>  3.3478],
        'Alimosho'         => ['lat' =>  6.6145, 'lon' =>  3.2617],
        'Amuwo-Odofin'     => ['lat' =>  6.4700, 'lon' =>  3.3167],
        'Apapa'            => ['lat' =>  6.4478, 'lon' =>  3.3640],
        'Badagry'          => ['lat' =>  6.4142, 'lon' =>  2.8834],
        'Epe'              => ['lat' =>  6.5867, 'lon' =>  3.9833],
        'Eti-Osa'          => ['lat' =>  6.4320, 'lon' =>  3.5921],
        'Ibeju-Lekki'      => ['lat' =>  6.4355, 'lon' =>  3.7836],
        'Ifako-Ijaiye'     => ['lat' =>  6.6531, 'lon' =>  3.2939],
        'Ikeja'            => ['lat' =>  6.5954, 'lon' =>  3.3378],
        'Ikorodu'          => ['lat' =>  6.6194, 'lon' =>  3.5014],
        'Kosofe'           => ['lat' =>  6.5833, 'lon' =>  3.4167],
        'Lagos Island'     => ['lat' =>  6.4550, 'lon' =>  3.3940],
        'Lagos Mainland'   => ['lat' =>  6.5057, 'lon' =>  3.3791],
        'Mushin'           => ['lat' =>  6.5235, 'lon' =>  3.3515],
        'Ojo'              => ['lat' =>  6.4685, 'lon' =>  3.1861],
        'Oshodi-Isolo'     => ['lat' =>  6.5567, 'lon' =>  3.3469],
        'Shomolu'          => ['lat' =>  6.5404, 'lon' =>  3.3833],
        'Surulere'         => ['lat' =>  6.4969, 'lon' =>  3.3481],
    ];

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

        // For now, coverage is ONLY available for Ikorodu.
        // Any other LGA should explicitly return a "not covered" response.
        if (strcasecmp($lga, 'Ikorodu') !== 0) {
            $formattedAddress = "{$lga}, {$state}, {$country}";

            return response()->json([
                'success' => false,
                'covered' => false,
                'searched_location' => [
                    'lga'       => $lga,
                    'state'     => $state,
                    'country'   => $country,
                    'address'   => $formattedAddress,
                ],
                'message' => "Sorry, we currently only cover Ikorodu. We're expanding to other LGAs soon!",
            ], 422);
        }

        // Use hardcoded LGA centroid for Ikorodu — avoids non-deterministic geocoding API results
        $centroid = $this->lagosLgaCentroids['Ikorodu'] ?? null;

        if (!$centroid) {
            return response()->json([
                'success' => false,
                'covered' => false,
                'message' => 'Unrecognised LGA. Please select a valid Lagos LGA.',
            ], 422);
        }

        $lat = $centroid['lat'];
        $lon = $centroid['lon'];
        $formattedAddress = "{$lga}, {$state}, {$country}";

        // 50 km radius — large enough to cover vendors anywhere within an LGA
        $radiusKm = 50;
    
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
