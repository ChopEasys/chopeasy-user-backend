<?php

namespace App\Services;

use App\Models\PricingConfig;
use App\Models\WeightTier;
use App\Models\RiderPayoutRule;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * PricingService - Core pricing calculation engine
 *
 * New model:
 * - Delivery fee = Base Fee + Weight Fee + Distance Fee (customer total unchanged)
 * - Platform takes platform_percentage of the weight fee; rider gets the rest of the weight fee + distance zone payout (not the order base fee)
 * - Service fee = percentage of customer-visible product subtotal
 * - Vendor payout = vendor base subtotal minus configured vendor take
 * - Product markup stays with the platform
 */
class PricingService
{
    private ?PricingConfig $pricingConfig = null;
    private ?WeightTier $weightTier = null;
    private ?RiderPayoutRule $riderPayoutRule = null;
    private string $regionId;

    public function __construct(?string $regionId = 'NG-DEFAULT')
    {
        $this->regionId = $regionId;
    }

    public function calculateOrderPricing(
        int $itemCount,
        float $totalWeight,
        float $distanceInKm,
        ?float $customerProductSubtotal = 0,
        ?float $vendorSubtotal = null
    ): array {
        $this->loadPricingConfig();
        $this->loadWeightTier($totalWeight);
        $this->loadRiderPayoutRule($distanceInKm, $totalWeight);

        $breakdown = $this->executeFormula(
            $itemCount,
            $totalWeight,
            $distanceInKm,
            (float) ($customerProductSubtotal ?? 0),
            $vendorSubtotal !== null ? (float) $vendorSubtotal : null
        );

        $breakdown['payout_breakdown'] = $this->calculatePayouts($breakdown);
        $breakdown['metadata'] = $this->getMetadata();

        return $breakdown;
    }

    private function executeFormula(
        int $itemCount,
        float $totalWeight,
        float $distanceInKm,
        float $customerProductSubtotal,
        ?float $vendorSubtotal
    ): array {
        $resolvedVendorSubtotal = $vendorSubtotal !== null
            ? max((float) $vendorSubtotal, 0)
            : max($customerProductSubtotal, 0);

        $resolvedCustomerSubtotal = max($customerProductSubtotal, 0);
        if ($resolvedCustomerSubtotal < $resolvedVendorSubtotal) {
            $resolvedCustomerSubtotal = $resolvedVendorSubtotal;
        }

        $productMarkupTotal = max($resolvedCustomerSubtotal - $resolvedVendorSubtotal, 0);

        $baseFee = (float) $this->pricingConfig->base_charge;
        $weightFee = $this->weightTier->calculateWeightFee($totalWeight);

        // Determine if base fee applies based on configurable threshold
        $baseFeeThreshold = (float) ($this->pricingConfig->base_fee_threshold ?? 20000);
        $baseFeeApplied = $resolvedCustomerSubtotal >= $baseFeeThreshold;

        // Use different platform % based on whether base fee is applied
        $platformPct = max(0, min(100, (float) ($this->weightTier->platform_percentage ?? 0)));
        if (!$baseFeeApplied) {
            // Higher platform % when base fee is waived (order is below threshold)
            $noBaseFeePlatformPct = (float) ($this->pricingConfig->no_base_fee_platform_percentage ?? 60);
            $platformPct = max(0, min(100, $noBaseFeePlatformPct));
        }

        $weightFeePlatformTake = round($weightFee * ($platformPct / 100), 2);
        $weightFeeRiderShare = round(max($weightFee - $weightFeePlatformTake, 0), 2);
        $serviceFeePercent = (float) ($this->pricingConfig->service_fee_percent ?? 0);
        $vendorTakePercent = (float) ($this->pricingConfig->vendor_take_percent ?? 0);
        $serviceFeeTotal = $resolvedCustomerSubtotal > 0
            ? ($resolvedCustomerSubtotal * $serviceFeePercent / 100)
            : 0;

        $zone = RiderPayoutRule::findZoneForDistance($distanceInKm, $this->regionId);
        $distanceFee = $zone ? $zone->getZoneFee() : 0;

        // Base fee is only added if customer subtotal meets the threshold
        $appliedBaseFee = $baseFeeApplied ? $baseFee : 0;
        $deliveryFeeTotal = $appliedBaseFee + $weightFee + $distanceFee;

        return [
            'base_charge' => round($baseFee, 2),
            'base_fee' => round($appliedBaseFee, 2),
            'base_fee_applied' => round($appliedBaseFee, 2),
            'base_fee_waived' => !$baseFeeApplied,
            'base_fee_threshold' => round($baseFeeThreshold, 2),
            'weight_fee' => round($weightFee, 2),
            'weight_service_fee' => round($weightFee, 2),
            'weight_platform_percentage' => round($platformPct, 2),
            'weight_fee_platform_take' => $weightFeePlatformTake,
            'weight_fee_rider_share' => $weightFeeRiderShare,
            'weight_price_per_kg' => $this->weightTier->price_per_kg ?? null,
            'distance_fee' => round($distanceFee, 2),
            'distance_charge_total' => round($distanceFee, 2),
            'distance_zone' => $zone?->zone_name,
            'service_fee_percent' => round($serviceFeePercent, 2),
            'service_fee_total' => round($serviceFeeTotal, 2),
            'service_charge_total' => round($serviceFeeTotal, 2),
            'vendor_take_percent' => round($vendorTakePercent, 2),
            'total_charge' => round($deliveryFeeTotal, 2),
            'delivery_fee_total' => round($deliveryFeeTotal, 2),
            'item_count' => $itemCount,
            'total_weight_kg' => round($totalWeight, 2),
            'distance_km' => round($distanceInKm, 2),
            'customer_product_subtotal' => round($resolvedCustomerSubtotal, 2),
            'vendor_subtotal' => round($resolvedVendorSubtotal, 2),
            'product_markup_total' => round($productMarkupTotal, 2),
        ];
    }

    private function calculatePayouts(array $breakdown): array
    {
        $deliveryFeeTotal = (float) ($breakdown['delivery_fee_total'] ?? $breakdown['total_charge'] ?? 0);
        $serviceFeeTotal = (float) ($breakdown['service_fee_total'] ?? 0);
        $vendorSubtotal = (float) ($breakdown['vendor_subtotal'] ?? 0);
        $customerSubtotal = (float) ($breakdown['customer_product_subtotal'] ?? $vendorSubtotal);
        $productMarkupTotal = (float) ($breakdown['product_markup_total'] ?? max($customerSubtotal - $vendorSubtotal, 0));
        $vendorTakePercent = (float) ($breakdown['vendor_take_percent'] ?? $this->pricingConfig?->vendor_take_percent ?? 0);
        $vendorTakeTotal = $vendorSubtotal > 0
            ? ($vendorSubtotal * $vendorTakePercent / 100)
            : 0;
        $vendorPayout = max($vendorSubtotal - $vendorTakeTotal, 0);

        $zone = RiderPayoutRule::findZoneForDistance($breakdown['distance_km'], $this->regionId);
        $distanceFee = (float) ($breakdown['distance_fee'] ?? $breakdown['distance_charge_total'] ?? 0);
        $distancePayout = $zone ? (float) $zone->getZoneFee() : ($this->riderPayoutRule
            ? (float) $this->riderPayoutRule->calculatePayout($breakdown['distance_km'], $breakdown['total_weight_kg'])
            : $distanceFee);

        $weightFeeRiderShare = (float) ($breakdown['weight_fee_rider_share'] ?? $breakdown['weight_fee'] ?? 0);
        // Rider receives their share of the weight fee plus the distance payout (not the order base fee).
        $riderPayout = round($weightFeeRiderShare + $distancePayout, 2);

        $platformRevenue = $productMarkupTotal + ($deliveryFeeTotal - $riderPayout) + $serviceFeeTotal + $vendorTakeTotal;
        $marginBase = $customerSubtotal + $deliveryFeeTotal + $serviceFeeTotal;
        $marginPercentage = $marginBase > 0
            ? round(($platformRevenue / $marginBase) * 100, 2)
            : 0;

        return [
            'rider_payout' => round($riderPayout, 2),
            'rider_distance_payout' => round($distancePayout, 2),
            'weight_fee_platform_take' => round((float) ($breakdown['weight_fee_platform_take'] ?? 0), 2),
            'weight_fee_rider_share' => round($weightFeeRiderShare, 2),
            'weight_platform_percentage' => round((float) ($breakdown['weight_platform_percentage'] ?? 0), 2),
            'vendor_gross_payout' => round($vendorSubtotal, 2),
            'vendor_take_percent' => round($vendorTakePercent, 2),
            'vendor_take_total' => round($vendorTakeTotal, 2),
            'vendor_payout' => round($vendorPayout, 2),
            'platform_revenue' => round($platformRevenue, 2),
            'product_markup_total' => round($productMarkupTotal, 2),
            'platform_margin_percentage' => $marginPercentage,
            'total_to_collect_from_customer' => round(
                $customerSubtotal + $deliveryFeeTotal + $serviceFeeTotal,
                2
            ),
        ];
    }

    private function loadPricingConfig(): void
    {
        $this->pricingConfig = PricingConfig::getActiveConfig($this->regionId);

        if (!$this->pricingConfig) {
            Log::error("No active pricing config found for region: {$this->regionId}");
            throw new Exception("Pricing configuration not available for region: {$this->regionId}");
        }
    }

    private function loadWeightTier(float $weight): void
    {
        $this->weightTier = WeightTier::findTierForWeight($weight, $this->regionId)
            ?? WeightTier::getActiveRate($this->regionId);

        if (!$this->weightTier) {
            Log::error("No weight tier found for weight: {$weight}kg in region: {$this->regionId}");
            throw new Exception("Weight tier not configured for {$weight}kg. Please configure weight tiers.");
        }
    }

    private function loadRiderPayoutRule(float $distance, float $weight): void
    {
        $this->riderPayoutRule = RiderPayoutRule::findRuleForDelivery($distance, $weight, $this->regionId);

        if (!$this->riderPayoutRule) {
            Log::warning("No rider payout rule found for distance: {$distance}km, weight: {$weight}kg");
        }
    }

    private function getMetadata(): array
    {
        $weightRange = isset($this->weightTier->price_per_kg)
            ? "NGN {$this->weightTier->price_per_kg}/kg"
            : "{$this->weightTier->min_weight}kg - {$this->weightTier->max_weight}kg";

        return [
            'pricing_config_id' => $this->pricingConfig->id,
            'pricing_config_name' => $this->pricingConfig->name,
            'weight_tier_id' => $this->weightTier->id,
            'weight_tier_range' => $weightRange,
            'rider_payout_rule_id' => $this->riderPayoutRule?->id,
            'region_id' => $this->regionId,
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    public function previewPricing(array $scenarios): array
    {
        $results = [];

        foreach ($scenarios as $scenario) {
            try {
                $results[] = [
                    'scenario' => $scenario,
                    'pricing' => $this->calculateOrderPricing(
                        $scenario['item_count'],
                        $scenario['total_weight'],
                        $scenario['distance_km'],
                        $scenario['customer_product_subtotal']
                            ?? $scenario['vendor_subtotal']
                            ?? 0,
                        $scenario['vendor_subtotal'] ?? null
                    ),
                ];
            } catch (Exception $e) {
                $results[] = [
                    'scenario' => $scenario,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function validateConfiguration(?string $regionId = null): array
    {
        $regionId = $regionId ?? $this->regionId;
        $issues = [];

        $config = PricingConfig::getActiveConfig($regionId);
        if (!$config) {
            $issues[] = "No active pricing configuration found for region: {$regionId}";
        }

        $tiers = WeightTier::forRegion($regionId)->active()->orderedByWeight()->get();
        if ($tiers->isEmpty()) {
            $issues[] = "No weight tiers configured for region: {$regionId}";
        } else {
            $maxWeight = $tiers->max('max_weight');
            if ($maxWeight < 50) {
                $issues[] = "Weight tiers only cover up to {$maxWeight}kg. Consider extending to 50kg.";
            }
        }

        $rules = RiderPayoutRule::forRegion($regionId)->active()->get();
        if ($rules->isEmpty()) {
            $issues[] = "No rider payout rules configured for region: {$regionId}";
        }

        return [
            'is_valid' => empty($issues),
            'issues' => $issues,
            'region_id' => $regionId,
        ];
    }

    public static function calculateDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }
}
