<?php

namespace App\Services;

use App\Responser\JsonResponser;
use Illuminate\Support\Facades\Http;

class GeoLocationService
{
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.ipgeolocation.key');
        $this->apiKey = config('services.google_maps.api_key');
    }

    public function getCountryAndCurrencyFromIP($ip)
    {
        $response = Http::get("https://api.ipgeolocation.io/ipgeo?apiKey={$this->apiKey}&ip={$ip}");

        if ($response->successful()) {
            $country = $response->json()['country_name'];

            if ($country === 'Nigeria') {
                $exchangeRate = $this->getExchangeRateToNGN();
                return ['country' => $country, 'currency' => 'NGN', 'exchange_rate' => $exchangeRate];
            }

            return ['country' => $country, 'currency' => 'USD'];
        }

        return ['country' => 'United States', 'currency' => 'USD'];
    }

    private function getExchangeRateToNGN()
    {
        $response = Http::get("https://open.er-api.com/v6/latest/USD");
        if ($response->successful()) {
            return $response->json()['rates']['NGN'];
        }
        return 400;
    }

    private function getCurrencyByCountry($country)
    {
        $currencyMap = [
            'United States' => '$',
            'Nigeria' => '₦',
        ];

        return $currencyMap[$country] ?? 'USD';
    }

    public function getGeoInfo($ip)
    {
        try {
            $response = Http::get("https://api.ipgeolocation.io/ipgeo?apiKey={$this->apiKey}&ip={$ip}");

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $th) {
            return JsonResponser::send(false, 'Failed to fetch geolocation data. Response.', [],  500, $th);
        }
    }

    public function getAnalyticsCountryAndCurrencyFromIP($ip)
    {
        try {
            $geoInfo = $this->getGeoInfo($ip);
            $country = $geoInfo['country_name'] ?? 'Unknown';

            if (!in_array($country, ['Nigeria', 'United States'])) {
                $country = 'United States';
            }

            $currency =  $this->getCurrencyByCountry($country);
            $state = $geoInfo['state_prov'] ?? 'Not Available';
            $zipcode = $geoInfo['zipcode'] ?? 'Not Available';
            $phoneCode = $geoInfo['calling_code'] ?? 'Not Available';
            $countryCode = $geoInfo['country_code2'] ?? 'Not Available';

            $vatRate = match ($country) {
                'United States' => 3,
                'Nigeria' => 7.5,
                default => 3,
            };

            $cateringCharges = 5000;
            if ($currency !== '₦') {
                $exchangeRate = $this->getExchangeRate();
                $cateringCharges = round(5000 * $exchangeRate, 2);
            }

            return [
                'country' => $country,
                'currency' => $currency,
                'state' => $state,
                'zipcode' => $zipcode,
                'phone_code' => $phoneCode,
                'country_code' => $countryCode,
                'vat_rate' => $vatRate,
                'catering' => [
                    'charges' => $cateringCharges
                ]
            ];
        } catch (\Throwable $th) {
            return JsonResponser::send(false, 'Failed to fetch analytics data.', [],  500, $th);
        }
    }

    private function getExchangeRate()
    {
        try {
            $response = Http::get('https://open.er-api.com/v6/latest/NGN');

            if ($response->successful()) {
                return $response->json()['rates']['USD'];
            }
        } catch (\Throwable $th) {
            return JsonResponser::send(false, 'Failed to fetch analytics data.', [],  500, $th);
        }
    }

    /**
     * Get latitude and longitude from an address string.
     *
     * @param string $address
     * @return array [$lat, $lng]
     */
    public function getCoordinatesFromAddress(string $address): array
{
    $response = Http::get('https://api.geoapify.com/v1/geocode/search', [
        'text'   => $address,
        'filter' => 'countrycode:ng',
        'limit'  => 1,
        'apiKey' => config('services.geoapify.key'),
    ])->json();

    if (!empty($response['features'][0]['geometry']['coordinates'])) {
        [$lon, $lat] = $response['features'][0]['geometry']['coordinates'];
        return [$lat, $lon]; // swapped — Geoapify returns [lon, lat]
    }

    return [null, null];
}
}
