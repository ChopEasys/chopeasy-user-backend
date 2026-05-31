<?php

namespace App\Services;

use App\Models\AgentEarning;
use App\Models\AgentReferralCommissionCounter;
use App\Models\Order;
use App\Models\RiderPayout;
use App\Models\User;
use App\Models\VendorPayout;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AgentCommissionReportService
{
    public function __construct(
        protected AgentCommissionService $agentCommissionService
    ) {}

    public function listOrders(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(50, max(5, (int) ($filters['per_page'] ?? 20)));
        $search = trim((string) ($filters['search'] ?? ''));
        $agentId = isset($filters['agent_id']) ? (int) $filters['agent_id'] : null;
        $onlyWithCredits = filter_var($filters['only_credited'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $query = Order::query()
            ->with([
                'user:id,fullname,email,referred_by_agent_id',
                'rider:id,fullname,email,referred_by_agent_id',
                'agentEarnings.agent:id,fullname,email',
            ])
            ->withSum('agentEarnings as total_agent_commission', 'amount')
            ->when($onlyWithCredits, fn (Builder $q) => $q->whereHas('agentEarnings'))
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('order_number', 'like', "%{$search}%")
                        ->orWhere('id', $search);
                });
            })
            ->when($agentId, function (Builder $q) use ($agentId) {
                $q->whereHas('agentEarnings', fn (Builder $inner) => $inner->where('agent_id', $agentId));
            })
            ->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    public function buildOrderReport(Order $order): array
    {
        $order->loadMissing([
            'user:id,fullname,email,referred_by_agent_id',
            'rider:id,fullname,email,referred_by_agent_id',
            'agentEarnings.agent:id,fullname,email',
            'agentEarnings.referredUser:id,fullname,email,user_type',
            'vendorPayouts.vendor:id,fullname,email,referred_by_agent_id',
            'riderPayouts.rider:id,fullname,email,referred_by_agent_id',
            'items.vendorOrders.vendor:id,fullname,email,referred_by_agent_id',
        ]);

        $settings = $this->agentCommissionService->settings();
        $revenue = $this->agentCommissionService->platformRevenueSummary($order);

        $vendorPayoutRows = $this->buildVendorPayoutRows($order);
        $riderPayoutRow = $this->buildRiderPayoutRow($order);

        $credited = $order->agentEarnings->map(function (AgentEarning $earning) {
            $meta = $this->agentCommissionService->describeEarning($earning);

            return [
                'id' => $earning->id,
                'earning_type' => $earning->earning_type,
                'agent' => $earning->agent ? [
                    'id' => $earning->agent->id,
                    'name' => $earning->agent->fullname,
                    'email' => $earning->agent->email,
                ] : null,
                'referred_user' => $earning->referredUser ? [
                    'id' => $earning->referredUser->id,
                    'name' => $earning->referredUser->fullname,
                    'email' => $earning->referredUser->email,
                    'user_type' => $earning->referredUser->user_type,
                ] : null,
                'commission_base_label' => $meta['commission_base_label'],
                'commission_base_amount' => $meta['commission_base_amount'],
                'commission_percent' => (float) $earning->commission_percent,
                'amount' => (float) $earning->amount,
                'status' => $earning->status,
                'created_at' => $earning->created_at?->toIso8601String(),
            ];
        })->values()->all();

        $expected = $this->buildExpectedCommissions($order, $settings, $vendorPayoutRows, $riderPayoutRow, $credited);

        return [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'total_amount' => (float) $order->total_amount,
                'completed_at' => $order->completed_at ?? null,
                'created_at' => $order->created_at?->toIso8601String(),
                'customer' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->fullname,
                    'email' => $order->user->email,
                    'referred_by_agent_id' => $order->user->referred_by_agent_id,
                ] : null,
            ],
            'commission_settings' => [
                'customer_percent' => (float) $settings->customer_percent,
                'vendor_percent' => (float) $settings->vendor_percent,
                'rider_percent' => (float) $settings->rider_percent,
                'max_vendor_rider_payout_commissions' => (int) $settings->max_vendor_rider_payout_commissions,
            ],
            'platform_revenue' => $revenue,
            'payouts' => [
                'vendor_payouts' => $vendorPayoutRows,
                'rider_payout' => $riderPayoutRow,
            ],
            'agent_commissions' => [
                'credited' => $credited,
                'expected' => $expected,
            ],
            'summary' => [
                'total_agent_commission_credited' => round(collect($credited)->sum('amount'), 2),
                'total_agent_commission_expected' => round(collect($expected)->sum('expected_amount'), 2),
                'platform_revenue_total' => $revenue['platform_revenue_total'],
            ],
        ];
    }

    public function formatListItem(Order $order): array
    {
        $earningTypes = $order->agentEarnings
            ->pluck('earning_type')
            ->unique()
            ->values()
            ->all();

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'total_amount' => (float) $order->total_amount,
            'customer_name' => $order->user?->fullname,
            'total_agent_commission' => round((float) ($order->total_agent_commission ?? 0), 2),
            'commission_count' => $order->agentEarnings->count(),
            'earning_types' => $earningTypes,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildVendorPayoutRows(Order $order): array
    {
        $payoutBreakdown = $this->decodePricing($order)['payout_breakdown'] ?? [];
        $vendorTakePercent = (float) ($payoutBreakdown['vendor_take_percent'] ?? 0);

        return $order->vendorPayouts->map(function (VendorPayout $payout) use ($order, $vendorTakePercent) {
            $vendor = $payout->vendor;
            $gross = $vendorTakePercent > 0
                ? round((float) $payout->amount / max(1 - ($vendorTakePercent / 100), 0.0001), 2)
                : (float) $payout->amount;
            $platformTake = $this->agentCommissionService->getVendorCommissionBase($order, null, $gross);

            return [
                'id' => $payout->id,
                'vendor' => $vendor ? [
                    'id' => $vendor->id,
                    'name' => $vendor->fullname,
                    'email' => $vendor->email,
                    'referred_by_agent_id' => $vendor->referred_by_agent_id,
                ] : null,
                'gross_amount' => $gross,
                'platform_take_amount' => $platformTake,
                'net_paid_to_vendor' => (float) $payout->amount,
                'status' => $payout->status,
                'transfer_reference' => $payout->transfer_reference,
                'transfer_code' => $payout->transfer_code,
                'paid_at' => $payout->paid_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildRiderPayoutRow(Order $order): ?array
    {
        $payout = $order->riderPayouts->first() ?? RiderPayout::where('order_id', $order->id)->first();
        if (!$payout) {
            $riderPayoutAmount = (float) ($order->rider_payout ?? 0);
            if ($riderPayoutAmount <= 0) {
                return null;
            }

            $rider = $order->rider;
            $revenue = $this->agentCommissionService->platformRevenueSummary($order);

            return [
                'id' => null,
                'rider' => $rider ? [
                    'id' => $rider->id,
                    'name' => $rider->fullname,
                    'email' => $rider->email,
                    'referred_by_agent_id' => $rider->referred_by_agent_id,
                ] : null,
                'amount' => $riderPayoutAmount,
                'delivery_fee_total' => $revenue['delivery_fee_total'],
                'platform_delivery_profit' => $revenue['delivery_profit'],
                'status' => 'not_initiated',
                'transfer_reference' => null,
                'transfer_code' => null,
                'paid_at' => null,
            ];
        }

        $revenue = $this->agentCommissionService->platformRevenueSummary($order);

        return [
            'id' => $payout->id,
            'rider' => $payout->rider ? [
                'id' => $payout->rider->id,
                'name' => $payout->rider->fullname,
                'email' => $payout->rider->email,
                'referred_by_agent_id' => $payout->rider->referred_by_agent_id,
            ] : null,
            'amount' => (float) $payout->amount,
            'delivery_fee_total' => $revenue['delivery_fee_total'],
            'platform_delivery_profit' => $this->agentCommissionService->getRiderCommissionBase($order, (float) $payout->amount),
            'status' => $payout->status,
            'transfer_reference' => $payout->transfer_reference,
            'transfer_code' => $payout->transfer_code,
            'paid_at' => $payout->paid_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $credited
     * @return list<array<string, mixed>>
     */
    protected function buildExpectedCommissions(
        Order $order,
        $settings,
        array $vendorPayoutRows,
        ?array $riderPayoutRow,
        array $credited
    ): array {
        $expected = [];
        $creditedKeys = collect($credited)->map(fn ($row) => ($row['earning_type'] ?? '') . ':' . ($row['referred_user']['id'] ?? ''))->all();

        $customer = $order->user;
        if ($customer?->referred_by_agent_id) {
            $base = $this->agentCommissionService->getCustomerCommissionBase($order);
            $key = 'customer_order:' . $customer->id;
            if (!in_array($key, $creditedKeys, true) && $base > 0) {
                $agent = User::find($customer->referred_by_agent_id);
                $pct = (float) $settings->customer_percent;
                $expected[] = $this->expectedRow(
                    'customer_order',
                    $agent,
                    $customer,
                    'Markup + service fee',
                    $base,
                    $pct,
                    $order->status === 'delivered' ? 'awaiting_delivery_confirm' : 'pending_delivery'
                );
            }
        }

        foreach ($vendorPayoutRows as $row) {
            $vendor = $row['vendor'] ?? null;
            if (!$vendor || empty($vendor['referred_by_agent_id'])) {
                continue;
            }

            $vendorId = (int) $vendor['id'];
            $key = 'vendor_payout:' . $vendorId;
            if (in_array($key, $creditedKeys, true)) {
                continue;
            }

            $agent = User::find($vendor['referred_by_agent_id']);
            $base = (float) ($row['platform_take_amount'] ?? 0);
            $pct = (float) $settings->vendor_percent;
            $status = in_array($row['status'] ?? '', ['paid', 'processing'], true)
                ? ($this->vendorRiderCapReached((int) $agent?->id, $vendorId, 'vendor', (int) $settings->max_vendor_rider_payout_commissions)
                    ? 'cap_reached'
                    : 'eligible_not_credited')
                : 'awaiting_vendor_payout';

            if ($base > 0) {
                $expected[] = $this->expectedRow(
                    'vendor_payout',
                    $agent,
                    User::find($vendorId),
                    'Platform vendor commission',
                    $base,
                    $pct,
                    $status
                );
            }
        }

        if ($riderPayoutRow && !empty($riderPayoutRow['rider']['referred_by_agent_id'])) {
            $riderId = (int) $riderPayoutRow['rider']['id'];
            $key = 'rider_payout:' . $riderId;
            if (!in_array($key, $creditedKeys, true)) {
                $agent = User::find($riderPayoutRow['rider']['referred_by_agent_id']);
                $base = (float) ($riderPayoutRow['platform_delivery_profit'] ?? 0);
                $pct = (float) $settings->rider_percent;
                $status = in_array($riderPayoutRow['status'] ?? '', ['paid', 'processing'], true)
                    ? ($this->vendorRiderCapReached((int) $agent?->id, $riderId, 'rider', (int) $settings->max_vendor_rider_payout_commissions)
                        ? 'cap_reached'
                        : 'eligible_not_credited')
                    : 'awaiting_rider_payout';

                if ($base > 0) {
                    $expected[] = $this->expectedRow(
                        'rider_payout',
                        $agent,
                        User::find($riderId),
                        'Platform delivery profit',
                        $base,
                        $pct,
                        $status
                    );
                }
            }
        }

        return $expected;
    }

    protected function expectedRow(
        string $type,
        ?User $agent,
        ?User $referred,
        string $baseLabel,
        float $base,
        float $pct,
        string $status
    ): array {
        return [
            'earning_type' => $type,
            'agent' => $agent ? [
                'id' => $agent->id,
                'name' => $agent->fullname,
                'email' => $agent->email,
            ] : null,
            'referred_user' => $referred ? [
                'id' => $referred->id,
                'name' => $referred->fullname,
                'email' => $referred->email,
                'user_type' => $referred->user_type,
            ] : null,
            'commission_base_label' => $baseLabel,
            'commission_base_amount' => round($base, 2),
            'commission_percent' => $pct,
            'expected_amount' => round($base * ($pct / 100), 2),
            'status' => $status,
        ];
    }

    protected function vendorRiderCapReached(int $agentId, int $referredUserId, string $kind, int $max): bool
    {
        if ($agentId <= 0) {
            return true;
        }

        $row = AgentReferralCommissionCounter::where([
            'agent_id' => $agentId,
            'referred_user_id' => $referredUserId,
            'referral_kind' => $kind,
        ])->first();

        return $row && (int) $row->payout_count >= $max;
    }

    protected function decodePricing(Order $order): array
    {
        $breakdown = $order->pricing_breakdown;

        return is_array($breakdown) ? $breakdown : (json_decode((string) $breakdown, true) ?: []);
    }
}
