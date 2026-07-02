<?php

namespace App\Services;

use App\Models\AgentCommissionSetting;
use App\Models\AgentEarning;
use App\Models\AgentReferralCommissionCounter;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentCommissionService
{
    public function settings(): AgentCommissionSetting
    {
        $row = AgentCommissionSetting::query()->first();
        if ($row) {
            return $row;
        }

        return AgentCommissionSetting::query()->create([
            'customer_percent' => 10,
            'vendor_percent' => 10,
            'agent_percent' => 10,
            'max_vendor_rider_payout_commissions' => 5,
            'downline_percent' => 15,
        ]);
    }

    public function creditCustomerOrderOnDeliveryConfirm(Order $order): void
    {
        $order->loadMissing('user');
        $user = $order->user;
        if (!$user || !$user->referred_by_agent_id) {
            return;
        }

        if (AgentEarning::where('order_id', $order->id)->where('earning_type', 'customer_order')->exists()) {
            return;
        }

        $agentId = (int) $user->referred_by_agent_id;
        $agent = User::find($agentId);
        if (!$agent || $agent->user_type !== 'agent') {
            return;
        }

        $settings = $this->settings();
        $pct = (float) $settings->customer_percent;
        $base = $this->customerCompanyRevenueBase($order);
        $amount = round($base * ($pct / 100), 2);
        if ($amount <= 0) {
            return;
        }

        $earning = DB::transaction(function () use ($agentId, $order, $base, $pct, $amount, $user) {
            $earning = AgentEarning::create([
                'agent_id' => $agentId,
                'order_id' => $order->id,
                'earning_type' => 'customer_order',
                'referred_user_id' => $user->id,
                'order_amount' => $base,
                'commission_percent' => $pct,
                'amount' => $amount,
                'status' => 'credited',
            ]);
            User::where('id', $agentId)->increment('main_wallet', $amount);
            return $earning;
        });

        $this->creditUplineFromDownlineEarning($agent, $earning);
    }

    /**
     * @param  list<array<string, mixed>>  $vendorPayoutEntries
     */
    public function creditVendorReferralsAfterPayout(Order $order, array $vendorPayoutEntries): void
    {
        $settings = $this->settings();
        $pct = (float) $settings->vendor_percent;
        $max = (int) $settings->max_vendor_rider_payout_commissions;

        foreach ($vendorPayoutEntries as $entry) {
            $vendorId = (int) ($entry['vendor_id'] ?? 0);
            if ($vendorId <= 0) {
                continue;
            }

            $status = strtolower((string) ($entry['status'] ?? ''));
            if (!in_array($status, ['paid', 'processing'], true)) {
                continue;
            }

            $vendor = User::find($vendorId);
            if (!$vendor || $vendor->user_type !== 'vendor' || !$vendor->referred_by_agent_id) {
                continue;
            }

            $agentId = (int) $vendor->referred_by_agent_id;
            $agent = User::find($agentId);
            if (!$agent || $agent->user_type !== 'agent') {
                continue;
            }

            if (
                AgentEarning::where('order_id', $order->id)
                    ->where('earning_type', 'vendor_payout')
                    ->where('referred_user_id', $vendorId)
                    ->exists()
            ) {
                continue;
            }

            if (!$this->canTakeVendorRiderCommission($agentId, $vendorId, 'vendor', $max)) {
                continue;
            }

            $vendorTakeAmount = (float) ($entry['vendor_take_amount'] ?? 0);
            $gross = (float) ($entry['gross_amount'] ?? 0);
            $base = $this->vendorCompanyProfitBase($order, $vendorTakeAmount, $gross);
            if ($base <= 0) {
                continue;
            }

            $amount = round($base * ($pct / 100), 2);
            if ($amount <= 0) {
                continue;
            }

            $earning = DB::transaction(function () use ($agentId, $order, $vendorId, $pct, $amount, $base) {
                $earning = AgentEarning::create([
                    'agent_id' => $agentId,
                    'order_id' => $order->id,
                    'earning_type' => 'vendor_payout',
                    'referred_user_id' => $vendorId,
                    'order_amount' => $base,
                    'commission_percent' => $pct,
                    'amount' => $amount,
                    'status' => 'credited',
                ]);
                User::where('id', $agentId)->increment('main_wallet', $amount);
                $this->incrementVendorRiderCounter($agentId, $vendorId, 'vendor');
                return $earning;
            });

            $this->creditUplineFromDownlineEarning($agent, $earning);
        }
    }

    /**
     * @param  array<string, mixed>|null  $agentPayout
     */
    public function creditAgentReferralAfterPayout(Order $order, ?array $agentPayout): void
    {
        if (!$agentPayout) {
            return;
        }

        $status = strtolower((string) ($agentPayout['status'] ?? ''));
        if (!in_array($status, ['paid', 'processing'], true)) {
            return;
        }

        $agentId = (int) ($agentPayout['agent_id'] ?? $order->accepted_by ?? 0);
        if ($agentId <= 0) {
            return;
        }

        $agent = User::find($agentId);
        if (!$agent || $agent->user_type !== 'agent' || !$agent->referred_by_agent_id) {
            return;
        }

        $uplineAgentId = (int) $agent->referred_by_agent_id;
        $uplineAgent = User::find($uplineAgentId);
        if (!$uplineAgent || $uplineAgent->user_type !== 'agent') {
            return;
        }

        if (
            AgentEarning::where('order_id', $order->id)
                ->where('earning_type', 'agent_payout')
                ->where('referred_user_id', $agentId)
                ->exists()
        ) {
            return;
        }

        $settings = $this->settings();
        $max = (int) $settings->max_vendor_rider_payout_commissions;
        if (!$this->canTakeVendorRiderCommission($uplineAgentId, $agentId, 'agent', $max)) {
            return;
        }

        $pct = (float) $settings->agent_percent;
        $base = $this->agentCompanyProfitBase($order, (float) ($agentPayout['amount'] ?? 0));
        $amount = round($base * ($pct / 100), 2);
        if ($amount <= 0) {
            return;
        }

        $earning = DB::transaction(function () use ($uplineAgentId, $order, $agentId, $pct, $amount, $base) {
            $earning = AgentEarning::create([
                'agent_id' => $uplineAgentId,
                'order_id' => $order->id,
                'earning_type' => 'agent_payout',
                'referred_user_id' => $agentId,
                'order_amount' => $base,
                'commission_percent' => $pct,
                'amount' => $amount,
                'status' => 'credited',
            ]);
            User::where('id', $uplineAgentId)->increment('main_wallet', $amount);
            $this->incrementVendorRiderCounter($uplineAgentId, $agentId, 'agent');
            return $earning;
        });

        $this->creditUplineFromDownlineEarning($uplineAgent, $earning);
    }

    /**
     * Credit the upline agent with a percentage of the downline agent's earning.
     *
     * @param  User          $agent   The agent who just earned a commission (potential downline)
     * @param  AgentEarning  $earning The earning record just created for the downline agent
     */
    public function creditUplineFromDownlineEarning(User $agent, AgentEarning $earning): void
    {
        if (!$agent->referred_by_agent_id) {
            return;
        }

        $uplineId = (int) $agent->referred_by_agent_id;
        $upline = User::find($uplineId);
        if (!$upline || $upline->user_type !== 'agent') {
            return;
        }

        $settings = $this->settings();
        $downlinePercent = (float) $settings->downline_percent;
        if ($downlinePercent <= 0) {
            return;
        }

        $earningAmount = (float) $earning->amount;
        if ($earningAmount <= 0) {
            return;
        }

        $uplineShare = round($earningAmount * $downlinePercent / 100, 2);
        if ($uplineShare <= 0) {
            return;
        }

        DB::transaction(function () use ($uplineId, $earning, $agent, $downlinePercent, $uplineShare) {
            AgentEarning::create([
                'agent_id' => $uplineId,
                'order_id' => $earning->order_id,
                'earning_type' => 'agent_downline',
                'referred_user_id' => $agent->id,
                'order_amount' => $earning->amount,
                'commission_percent' => $downlinePercent,
                'amount' => $uplineShare,
                'status' => 'credited',
            ]);
            User::where('id', $uplineId)->increment('main_wallet', $uplineShare);
        });
    }

    public function describeEarning(AgentEarning $earning): array
    {
        return [
            'commission_base_amount' => $this->recordedCommissionBaseAmount($earning),
            'commission_base_label' => match ($earning->earning_type) {
                'customer_order' => 'Markup + service fee',
                'vendor_payout' => 'Platform vendor commission',
                'agent_payout' => 'Platform delivery profit',
                'agent_downline' => 'Downline agent earning',
                default => 'Commission base',
            },
        ];
    }

    /**
     * Platform revenue components for admin reporting.
     */
    public function platformRevenueSummary(Order $order): array
    {
        $breakdown = $this->pricingBreakdown($order);
        $payoutBreakdown = $this->payoutBreakdown($order);

        $markup = (float) ($breakdown['product_markup_total']
            ?? $payoutBreakdown['product_markup_total']
            ?? 0);
        $serviceFee = (float) ($order->service_fee_total
            ?? $breakdown['service_fee_total']
            ?? $breakdown['service_charge_total']
            ?? 0);

        if ($markup <= 0) {
            $customerSubtotal = (float) ($order->customer_product_subtotal
                ?? $breakdown['customer_product_subtotal']
                ?? 0);
            $vendorSubtotal = (float) ($breakdown['vendor_subtotal'] ?? 0);
            if ($customerSubtotal > 0 && $vendorSubtotal > 0) {
                $markup = max($customerSubtotal - $vendorSubtotal, 0);
            }
        }

        $deliveryFeeTotal = (float) ($order->delivery_fee_total
            ?? $breakdown['delivery_fee_total']
            ?? $breakdown['total_charge']
            ?? 0);
        $riderPayout = (float) ($payoutBreakdown['rider_payout'] ?? $order->rider_payout ?? 0);
        $deliveryProfit = round(max($deliveryFeeTotal - $riderPayout, 0), 2);
        $vendorTakeTotal = (float) ($payoutBreakdown['vendor_take_total'] ?? 0);
        $customerBase = round($markup + $serviceFee, 2);
        $platformRevenueTotal = (float) ($order->platform_revenue
            ?? $payoutBreakdown['platform_revenue']
            ?? ($customerBase + $deliveryProfit + $vendorTakeTotal));

        return [
            'product_markup' => round($markup, 2),
            'service_fee' => round($serviceFee, 2),
            'customer_commission_base' => $customerBase,
            'delivery_fee_total' => round($deliveryFeeTotal, 2),
            'rider_payout' => round($riderPayout, 2),
            'delivery_profit' => $deliveryProfit,
            'vendor_take_total' => round($vendorTakeTotal, 2),
            'platform_revenue_total' => round($platformRevenueTotal, 2),
        ];
    }

    public function getCustomerCommissionBase(Order $order): float
    {
        return $this->customerCompanyRevenueBase($order);
    }

    public function getVendorCommissionBase(Order $order, ?float $platformTakeAmount = null, ?float $grossAmount = null): float
    {
        return $this->vendorCompanyProfitBase($order, $platformTakeAmount, $grossAmount);
    }

    public function getRiderCommissionBase(Order $order, ?float $riderPayoutAmount = null): float
    {
        return $this->agentCompanyProfitBase($order, $riderPayoutAmount);
    }

    public function getAgentCommissionBase(Order $order, ?float $agentPayoutAmount = null): float
    {
        return $this->agentCompanyProfitBase($order, $agentPayoutAmount);
    }

    protected function canTakeVendorRiderCommission(int $agentId, int $referredUserId, string $kind, int $max): bool
    {
        $row = AgentReferralCommissionCounter::firstOrCreate(
            [
                'agent_id' => $agentId,
                'referred_user_id' => $referredUserId,
                'referral_kind' => $kind,
            ],
            ['payout_count' => 0]
        );

        return (int) $row->payout_count < $max;
    }

    protected function incrementVendorRiderCounter(int $agentId, int $referredUserId, string $kind): void
    {
        AgentReferralCommissionCounter::where([
            'agent_id' => $agentId,
            'referred_user_id' => $referredUserId,
            'referral_kind' => $kind,
        ])->increment('payout_count');
    }

    protected function customerCompanyRevenueBase(Order $order): float
    {
        $breakdown = $this->pricingBreakdown($order);
        $payoutBreakdown = $this->payoutBreakdown($order);

        $markup = (float) ($breakdown['product_markup_total']
            ?? $payoutBreakdown['product_markup_total']
            ?? 0);
        $serviceFee = (float) ($order->service_fee_total
            ?? $breakdown['service_fee_total']
            ?? $breakdown['service_charge_total']
            ?? 0);

        if ($markup <= 0) {
            $customerSubtotal = (float) ($order->customer_product_subtotal
                ?? $breakdown['customer_product_subtotal']
                ?? 0);
            $vendorSubtotal = (float) ($breakdown['vendor_subtotal'] ?? 0);
            if ($customerSubtotal > 0 && $vendorSubtotal > 0) {
                $markup = max($customerSubtotal - $vendorSubtotal, 0);
            }
        }

        // Customer-referral agents share only markup + service fee — not delivery margin or vendor take.
        $base = $markup + $serviceFee;

        return round(max($base, 0), 2);
    }

    protected function vendorCompanyProfitBase(Order $order, ?float $platformTakeAmount = null, ?float $grossAmount = null): float
    {
        if ($platformTakeAmount !== null && $platformTakeAmount > 0) {
            return round($platformTakeAmount, 2);
        }

        $breakdown = $this->pricingBreakdown($order);
        $payoutBreakdown = $this->payoutBreakdown($order);

        $vendorTakeTotal = (float) ($payoutBreakdown['vendor_take_total'] ?? 0);
        if ($vendorTakeTotal > 0) {
            return round($vendorTakeTotal, 2);
        }

        $vendorTakePercent = (float) ($payoutBreakdown['vendor_take_percent']
            ?? $breakdown['vendor_take_percent']
            ?? 0);
        $vendorGrossAmount = $grossAmount !== null && $grossAmount > 0 ? $grossAmount : 0.0;
        $platformTake = $vendorGrossAmount > 0
            ? round($vendorGrossAmount * ($vendorTakePercent / 100), 2)
            : 0.0;

        return round(max($platformTake, 0), 2);
    }

    protected function agentCompanyProfitBase(Order $order, ?float $agentPayoutAmount = null): float
    {
        $breakdown = $this->pricingBreakdown($order);
        $payoutBreakdown = $this->payoutBreakdown($order);

        $deliveryFeeTotal = (float) ($order->delivery_fee_total
            ?? ($breakdown['delivery_fee_total'] ?? $breakdown['total_charge'] ?? 0));
        $agentPayout = $agentPayoutAmount !== null && $agentPayoutAmount > 0
            ? $agentPayoutAmount
            : (float) ($payoutBreakdown['rider_payout'] ?? $order->rider_payout ?? 0);

        return round(max($deliveryFeeTotal - $agentPayout, 0), 2);
    }

    protected function recordedCommissionBaseAmount(AgentEarning $earning): float
    {
        $percent = (float) $earning->commission_percent;
        if ($percent > 0 && (float) $earning->amount > 0) {
            return round(((float) $earning->amount * 100) / $percent, 2);
        }

        return round((float) ($earning->order_amount ?? 0), 2);
    }

    protected function pricingBreakdown(Order $order): array
    {
        $breakdown = $order->pricing_breakdown;
        if (is_array($breakdown)) {
            return $breakdown;
        }

        $decoded = json_decode((string) $breakdown, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function payoutBreakdown(Order $order): array
    {
        $breakdown = $this->pricingBreakdown($order);
        $payoutBreakdown = $breakdown['payout_breakdown'] ?? null;

        return is_array($payoutBreakdown) ? $payoutBreakdown : [];
    }
}
