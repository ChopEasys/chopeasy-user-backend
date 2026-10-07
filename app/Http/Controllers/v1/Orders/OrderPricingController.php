<?php

namespace App\Http\Controllers\v1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderEstimateRequest;
use App\Services\PricingService;
use Exception;
use Illuminate\Http\JsonResponse;

class OrderPricingController extends Controller
{
    public function estimate(OrderEstimateRequest $request): JsonResponse
    {
        try {
            $calculated = $request->getCalculatedValues();

            $distance = PricingService::calculateDistance(
                $request->pickup_latitude,
                $request->pickup_longitude,
                $request->delivery_latitude,
                $request->delivery_longitude
            );

            $regionId = $request->input('region_id', 'NG-DEFAULT');
            $pricingService = new PricingService($regionId);

            $pricing = $pricingService->calculateOrderPricing(
                $calculated['item_count'],
                $calculated['total_weight'],
                $distance,
                $calculated['customer_product_subtotal'],
                $calculated['vendor_subtotal']
            );

            // The browser only needs customer-facing totals. Keep internal vendor,
            // rider, platform-revenue, and pricing-configuration details server-side.
            $productSubtotal = (float) $pricing['customer_product_subtotal'];
            $deliveryFee = (float) ($pricing['delivery_fee_total'] ?? $pricing['total_charge'] ?? 0);
            $serviceFee = (float) ($pricing['service_fee_total'] ?? $pricing['service_charge_total'] ?? 0);

            return response()->json([
                'success' => true,
                'message' => 'Order estimate calculated successfully',
                'data' => [
                    'order_summary' => [
                        'item_count' => $calculated['item_count'],
                        'product_subtotal' => $productSubtotal,
                    ],
                    'pricing' => [
                        'customer_product_subtotal' => $productSubtotal,
                        'delivery_fee_total' => $deliveryFee,
                        'total_charge' => $deliveryFee,
                        'service_fee_total' => $serviceFee,
                        'service_charge_total' => $serviceFee,
                        'total_to_pay' => $productSubtotal + $deliveryFee + $serviceFee,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate order estimate',
            ], 500);
        }
    }

    private function getPricingExplanation(array $pricing): array
    {
        $productSubtotal = (float) ($pricing['customer_product_subtotal'] ?? $pricing['vendor_subtotal'] ?? 0);
        $serviceFeePercent = (float) ($pricing['service_fee_percent'] ?? 0);
        $serviceFeeTotal = (float) ($pricing['service_fee_total'] ?? $pricing['service_charge_total'] ?? 0);
        $baseFee = (float) ($pricing['base_fee'] ?? $pricing['base_charge'] ?? 0);
        $weightFee = (float) ($pricing['weight_fee'] ?? $pricing['weight_service_fee'] ?? 0);
        $distanceFee = (float) ($pricing['distance_fee'] ?? $pricing['distance_charge_total'] ?? 0);
        $deliveryFeeTotal = (float) ($pricing['delivery_fee_total'] ?? $pricing['total_charge'] ?? 0);
        $vendorGrossPayout = (float) ($pricing['payout_breakdown']['vendor_gross_payout'] ?? $pricing['vendor_subtotal'] ?? 0);
        $vendorTakePercent = (float) ($pricing['payout_breakdown']['vendor_take_percent'] ?? $pricing['vendor_take_percent'] ?? 0);
        $vendorTakeTotal = (float) ($pricing['payout_breakdown']['vendor_take_total'] ?? 0);
        $vendorNetPayout = (float) ($pricing['payout_breakdown']['vendor_payout'] ?? $vendorGrossPayout);

        return [
            'service_fee_breakdown' => [
                'service_fee' => [
                    'amount' => $serviceFeeTotal,
                    'calculation' => "{$serviceFeePercent}% of product subtotal",
                    'description' => 'Platform service fee charged on the selected products',
                ],
            ],
            'delivery_fee_breakdown' => [
                'base_fee' => [
                    'amount' => $baseFee,
                    'description' => 'Fixed base delivery fee per order',
                ],
                'weight_fee' => [
                    'amount' => $weightFee,
                    'calculation' => "{$pricing['total_weight_kg']}kg multiplied by the active per-kg rate",
                    'description' => 'Delivery fee based on the combined order weight',
                ],
                'distance_fee' => [
                    'amount' => $distanceFee,
                    'calculation' => $pricing['distance_zone']
                        ? "Zone fee for {$pricing['distance_zone']}"
                        : 'Calculated from delivery distance',
                    'description' => 'Zone-based delivery fee from vendor to customer',
                ],
            ],
            'total_delivery_fee' => $deliveryFeeTotal,
            'payment_summary' => [
                'product_cost' => $productSubtotal,
                'vendor_items_cost' => (float) ($pricing['vendor_subtotal'] ?? $productSubtotal),
                'vendor_gross_payout' => $vendorGrossPayout,
                'vendor_take_percent' => $vendorTakePercent,
                'vendor_take_total' => $vendorTakeTotal,
                'vendor_net_payout' => $vendorNetPayout,
                'service_fee' => $serviceFeeTotal,
                'delivery_fee' => $deliveryFeeTotal,
                'total_to_pay' => $pricing['payout_breakdown']['total_to_collect_from_customer'],
            ],
        ];
    }

    public function calculateDistance(): JsonResponse
    {
        request()->validate([
            'pickup_latitude' => 'required|numeric|between:-90,90',
            'pickup_longitude' => 'required|numeric|between:-180,180',
            'delivery_latitude' => 'required|numeric|between:-90,90',
            'delivery_longitude' => 'required|numeric|between:-180,180',
        ]);

        $distance = PricingService::calculateDistance(
            request('pickup_latitude'),
            request('pickup_longitude'),
            request('delivery_latitude'),
            request('delivery_longitude')
        );

        return response()->json([
            'success' => true,
            'data' => [
                'distance_km' => $distance,
                'distance_meters' => round($distance * 1000, 2),
            ],
        ]);
    }

    public function getPricingRates(): JsonResponse
    {
        try {
            $regionId = request('region_id', 'NG-DEFAULT');
            $pricingService = new PricingService($regionId);

            $validation = $pricingService->validateConfiguration($regionId);

            if (!$validation['is_valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pricing configuration incomplete',
                    'issues' => $validation['issues'],
                ], 500);
            }

            $config = \App\Models\PricingConfig::getActiveConfig($regionId);
            $weightTiers = \App\Models\WeightTier::forRegion($regionId)
                ->active()
                ->orderedByWeight()
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'base_charge' => $config->base_charge,
                    'service_fee_percent' => $config->service_fee_percent,
                    'product_markup_percent' => $config->product_markup_percent,
                    'vendor_take_percent' => $config->vendor_take_percent,
                    'delivery_pricing_model' => [
                        'base_fee' => $config->base_charge,
                        'distance_fee' => 'Zone-based',
                        'weight_fee_per_kg' => $weightTiers->first()?->price_per_kg,
                    ],
                    'weight_tiers' => $weightTiers->map(function ($tier) {
                        return [
                            'weight_range' => "{$tier->min_weight}kg - {$tier->max_weight}kg",
                            'price_per_kg' => $tier->price_per_kg,
                            'calculated_weight_fee' => $tier->calculateWeightFee((float) $tier->max_weight),
                        ];
                    }),
                    'region_id' => $regionId,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pricing rates',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
