<?php

namespace App\Http\Controllers\v1\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentBankDetail;
use App\Models\AgentCommissionSetting;
use App\Models\AgentCustomerNotificationPref;
use App\Models\AgentEarning;
use App\Models\AgentWithdrawal;
use App\Models\AgentWithdrawalLine;
use App\Models\Order;
use App\Models\User;
 use App\Models\DeliveryTierSetting;
use App\Responser\JsonResponser;
use App\Services\AgentCommissionService;
use App\Support\PaystackClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use App\Support\DeliveryTier;

class AgentController extends Controller
{
    /**
     * Minimum completed deliveries a Tier 1 agent needs before
     * they're allowed to request a Tier 2 upgrade.
     */

    public function dashboard(Request $request, AgentCommissionService $agentCommissionService)
    {
        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $settings = AgentCommissionSetting::query()->first();
        // if (!$settings) {
        //     $settings = AgentCommissionSetting::query()->create([
        //         'customer_percent' => 10,
        //         'vendor_percent' => 10,
        //         'rider_percent' => 10,
        //         'max_vendor_rider_payout_commissions' => 5,
        //     ]);
        // }

        $ref = (string) $user->id;
        $recentEarnings = AgentEarning::where('agent_id', $user->id)
            ->with('order:id,order_number,total_amount,created_at')
            ->latest()
            ->take(10)
            ->get();

        $user->unsetRelation('agentBankDetails');
        $bankDetails = $user->agentBankDetails()->first();

        $mkLink = static function (string $base, string $type) use ($ref): string {
            return rtrim($base, '/') . '/register?ref=' . $ref . '&type=' . $type;
        };

        // Extract tier number from delivery_agent_tier field (format: "tier_1", "tier_2", etc.)
        $tierNumber = 1;
        if ($user->delivery_agent_tier) {
            preg_match('/tier_(\d+)/', $user->delivery_agent_tier, $matches);
            $tierNumber = $matches[1] ?? 1;
        }

        return JsonResponser::send(false, 'Dashboard loaded.', [
            'user' => [
                'id' => $user->id,
                'fullname' => $user->fullname,
                'email' => $user->email,
                'phoneno' => $user->phoneno,
                'user_type' => $user->user_type,
                'is_delivery_agent' => (bool) $user->is_delivery_agent,
                'delivery_agent_application_status' => $user->delivery_agent_application_status,
                'delivery_tier' => $tierNumber,
                'delivery_tier_name' => 'Tier ' . $tierNumber,
                'tier_upgrade_status' => $user->tier_upgrade_status,
            ],
            'wallet_balance' => (float) $user->main_wallet,
            'commission_settings' => [
                'customer_percent' => (float) $settings->customer_percent,
                'vendor_percent' => (float) $settings->vendor_percent,
                'rider_percent' => (float) $settings->rider_percent,
                'max_vendor_rider_payout_commissions' => (int) $settings->max_vendor_rider_payout_commissions,
            ],
            'referrals' => [
                'customer' => [
                    'link' => $mkLink(config('app.register_base_customer'), 'customer'),
                    'title' => 'Refer customers',
                    'description' => 'Earn ' . $settings->customer_percent . '% of markup and service fees on each completed order from customers you referred.',
                ],
                'vendor' => [
                    'link' => $mkLink(config('app.register_base_vendor'), 'vendor'),
                    'title' => 'Refer vendors',
                    'description' => 'Earn ' . $settings->vendor_percent . '% of the platform commission on each vendor payout from vendors you referred (up to ' . $settings->max_vendor_rider_payout_commissions . ' payouts per vendor).',
                ],
                'agent' => [
                    'link' => $mkLink(config('app.register_base_rider'), 'agent'),
                    'title' => 'Refer agents',
                    'description' => 'Earn ' . $settings->rider_percent . '% of the platform delivery profit (delivery fee minus agent payout) on each completed delivery by agents you referred (up to ' . $settings->max_vendor_rider_payout_commissions . ' payouts per agent).',
                ],
            ],
            'referral_code' => $ref,
            'referred_customers_count' => User::where('referred_by_agent_id', $user->id)->where('user_type', 'customer')->count(),
            'referred_vendors_count' => User::where('referred_by_agent_id', $user->id)->where('user_type', 'vendor')->count(),
            'referred_riders_count' => User::where('referred_by_agent_id', $user->id)->where('user_type', 'agent')->count(),
            'bank_details' => $bankDetails ? [
                'bank_name' => $bankDetails->bank_name,
                'bank_code' => $bankDetails->bank_code,
                'account_number' => $bankDetails->account_number,
                'account_name' => $bankDetails->account_name,
            ] : null,
            'recent_earnings' => $recentEarnings->map(function ($e) use ($agentCommissionService) {
                $commission = $agentCommissionService->describeEarning($e);

                return [
                    'id' => $e->id,
                    'earning_type' => $e->earning_type,
                    'order_number' => $e->order?->order_number,
                    'order_amount' => (float) $e->order_amount,
                    'order_total' => $e->order ? (float) $e->order->total_amount : null,
                    'commission_percent' => (float) $e->commission_percent,
                    'amount' => (float) $e->amount,
                    'commission_base_amount' => $commission['commission_base_amount'],
                    'commission_base_label' => $commission['commission_base_label'],
                    'created_at' => $e->created_at->toIso8601String(),
                ];
            }),
        ], 200);
    }

    /**
     * Get minimum withdrawal amount (deprecated - kept for compatibility)
     */
    public function withdrawalPrefixSums(Request $request)
    {
        $user = $request->user();
        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        // Return empty array since we no longer use prefix sums
        return JsonResponser::send(false, 'Withdrawal options loaded.', [
            'valid_amounts' => [],
        ], 200);
    }

    public function transactions(Request $request, AgentCommissionService $agentCommissionService)
    {
        $user = $request->user();
        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $type = strtolower((string) $request->query('type', 'customer'));
        $map = [
            'customer' => 'customer_order',
            'vendor' => 'vendor_payout',
            'agent' => 'rider_payout',
        ];
        if (!isset($map[$type])) {
            return JsonResponser::send(true, 'Invalid type. Use customer, vendor, or agent.', null, 422);
        }

        $earningType = $map[$type];
        $perPage = min(50, max(5, (int) $request->query('per_page', 20)));

        $paginator = AgentEarning::where('agent_id', $user->id)
            ->where('earning_type', $earningType)
            ->with(['order:id,order_number,total_amount,status', 'referredUser:id,fullname,email'])
            ->latest()
            ->paginate($perPage);

        $items = $paginator->getCollection()->map(function ($e) use ($agentCommissionService) {
            $commission = $agentCommissionService->describeEarning($e);

            return [
                'id' => $e->id,
                'earning_type' => $e->earning_type,
                'amount' => (float) $e->amount,
                'order_number' => $e->order?->order_number,
                'order_total' => $e->order ? (float) $e->order->total_amount : null,
                'commission_percent' => (float) $e->commission_percent,
                'commission_base_amount' => $commission['commission_base_amount'],
                'commission_base_label' => $commission['commission_base_label'],
                'referred_name' => $e->referredUser?->fullname,
                'created_at' => $e->created_at->toIso8601String(),
            ];
        });

        return JsonResponser::send(false, 'Transactions loaded.', [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200);
    }

    public function referredCustomers(Request $request)
    {
        $agent = $request->user();
        if ($agent->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $customers = User::where('referred_by_agent_id', $agent->id)
            ->where('user_type', 'customer')
            ->orderByDesc('created_at')
            ->get(['id', 'fullname', 'email', 'phoneno', 'last_login', 'onboarding_completed', 'created_at']);

        $prefs = AgentCustomerNotificationPref::where('agent_id', $agent->id)
            ->whereIn('customer_user_id', $customers->pluck('id'))
            ->get()
            ->keyBy('customer_user_id');

        $now = Carbon::now();
        $rows = $customers->map(function ($c) use ($prefs, $now) {
            $p = $prefs->get($c->id);
            $inactive = !$c->last_login || Carbon::parse($c->last_login)->lt($now->copy()->subDays(30));

            return [
                'id' => $c->id,
                'fullname' => $c->fullname,
                'email' => $c->email,
                'phoneno' => $c->phoneno,
                'last_login' => $c->last_login ? Carbon::parse($c->last_login)->toIso8601String() : null,
                'is_inactive' => $inactive,
                'onboarding_completed' => (bool) $c->onboarding_completed,
                'notify_inactive' => $p ? (bool) $p->notify_inactive : false,
                'notify_incomplete_onboarding' => $p ? (bool) $p->notify_incomplete_onboarding : false,
            ];
        });

        return JsonResponser::send(false, 'Referred customers loaded.', [
            'customers' => $rows,
        ], 200);
    }

    public function updateCustomerNotificationPrefs(Request $request)
    {
        $agent = $request->user();
        if ($agent->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'customer_user_id' => 'required|integer|exists:users,id',
            'notify_inactive' => 'sometimes|boolean',
            'notify_incomplete_onboarding' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
                'data' => null,
            ], 422);
        }

        $customerId = (int) $validator->validated()['customer_user_id'];
        $customer = User::where('id', $customerId)->where('referred_by_agent_id', $agent->id)->where('user_type', 'customer')->first();
        if (!$customer) {
            return JsonResponser::send(true, 'Customer not found for this agent.', null, 404);
        }

        $updates = [];
        if ($request->has('notify_inactive')) {
            $updates['notify_inactive'] = $request->boolean('notify_inactive');
        }
        if ($request->has('notify_incomplete_onboarding')) {
            $updates['notify_incomplete_onboarding'] = $request->boolean('notify_incomplete_onboarding');
        }
        if ($updates === []) {
            return JsonResponser::send(true, 'Provide notify_inactive and/or notify_incomplete_onboarding.', null, 422);
        }

        $pref = AgentCustomerNotificationPref::updateOrCreate(
            ['agent_id' => $agent->id, 'customer_user_id' => $customerId],
            $updates
        );

        return JsonResponser::send(false, 'Preferences saved.', ['pref' => $pref], 200);
    }

    public function sendCustomerReminder(Request $request)
    {
        $agent = $request->user();
        if ($agent->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'customer_user_id' => 'required|integer|exists:users,id',
            'reason' => 'required|in:inactive,incomplete_onboarding',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
                'data' => null,
            ], 422);
        }

        $customerId = (int) $validator->validated()['customer_user_id'];
        $customer = User::where('id', $customerId)->where('referred_by_agent_id', $agent->id)->where('user_type', 'customer')->first();
        if (!$customer || !$customer->email) {
            return JsonResponser::send(true, 'Customer not found or has no email.', null, 422);
        }

        $pref = AgentCustomerNotificationPref::where('agent_id', $agent->id)->where('customer_user_id', $customerId)->first();
        $reason = $validator->validated()['reason'];
        if ($reason === 'inactive' && !($pref?->notify_inactive)) {
            return JsonResponser::send(true, 'Enable “notify inactive” for this customer first.', null, 422);
        }
        if ($reason === 'incomplete_onboarding' && !($pref?->notify_incomplete_onboarding)) {
            return JsonResponser::send(true, 'Enable “notify incomplete onboarding” for this customer first.', null, 422);
        }

        $subject = $reason === 'inactive'
            ? 'We miss you on ChopEasy'
            : 'Complete your ChopEasy setup';

        $body = $reason === 'inactive'
            ? "Hi {$customer->fullname},\n\nYour ChopEasy agent {$agent->fullname} noticed you have not been active recently. Open the app to continue enjoying great food deals.\n\n— ChopEasy"
            : "Hi {$customer->fullname},\n\nYour ChopEasy agent {$agent->fullname} noticed you have not finished setting up your account. Please complete onboarding in the app.\n\n— ChopEasy";

        try {
            Mail::raw($body, function ($message) use ($customer, $subject) {
                $message->to($customer->email)->subject($subject);
            });
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Could not send email: ' . $e->getMessage(), null, 500);
        }

        return JsonResponser::send(false, 'Reminder sent.', null, 200);
    }

    public function updateBankDetails(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'bank_name' => 'required|string|max:255',
            'bank_code' => 'required|string|max:50',
            'account_number' => 'required|string|max:50',
            'account_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
                'data' => null,
            ], 422);
        }

        $payload = $validator->validated();

        $bankDetails = AgentBankDetail::query()->updateOrCreate(
            ['user_id' => $user->id],
            array_merge($payload, ['user_id' => $user->id])
        );

        AgentBankDetail::where('user_id', $user->id)
            ->where('id', '!=', $bankDetails->id)
            ->delete();

        $bankDetails->refresh();
        $user->unsetRelation('agentBankDetails');

        return JsonResponser::send(false, 'Bank details updated.', [
            'bank_details' => [
                'bank_name' => $bankDetails->bank_name,
                'bank_code' => $bankDetails->bank_code,
                'account_number' => $bankDetails->account_number,
                'account_name' => $bankDetails->account_name,
            ],
        ], 200);
    }

    public function listBanks(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        if (!PaystackClient::configured()) {
            return JsonResponser::send(true, 'Paystack secret key not configured on server.', null, 500);
        }

        $response = PaystackClient::get('bank', [
            'country' => 'nigeria',
        ]);

        if (!$response->ok() || !($response->json('status') === true)) {
            $message = $response->json('message') ?? 'Unable to load banks.';

            return JsonResponser::send(true, $message, null, 422);
        }

        $banks = collect($response->json('data') ?? [])
            ->map(fn ($bank) => [
                'name' => $bank['name'] ?? '',
                'code' => $bank['code'] ?? '',
            ])
            ->filter(fn ($bank) => $bank['name'] && $bank['code'])
            ->values();

        return JsonResponser::send(false, 'Banks loaded.', [
            'banks' => $banks,
        ], 200);
    }

    public function resolveBankDetails(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'bank_code' => 'required|string|max:50',
            'account_number' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
                'data' => null,
            ], 422);
        }

        if (!PaystackClient::configured()) {
            return JsonResponser::send(true, 'Paystack secret key not configured on server.', null, 500);
        }

        $payload = $validator->validated();
        $bankCode = trim($payload['bank_code']);
        $accountNumber = trim($payload['account_number']);

        $response = PaystackClient::get('bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        if (!$response->ok() || !($response->json('status') === true)) {
            $message = $response->json('message') ?? 'Unable to resolve account name.';

            return JsonResponser::send(true, $message, null, 422);
        }

        $data = $response->json('data') ?? [];

        return JsonResponser::send(false, 'Account resolved.', [
            'account_name' => $data['account_name'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
        ], 200);
    }

    public function requestWithdrawal(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
                'data' => null,
            ], 422);
        }

        $bankDetails = $user->agentBankDetails;
        if (!$bankDetails) {
            return JsonResponser::send(true, 'Please add your bank details before withdrawing.', null, 422);
        }

        $amount = round((float) $validator->validated()['amount'], 2);

        if ($user->main_wallet < $amount) {
            return JsonResponser::send(true, 'Insufficient wallet balance.', null, 422);
        }

        $withdrawal = null;

        try {
            DB::transaction(function () use ($user, $bankDetails, $amount, &$withdrawal) {
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
                if ((float) $lockedUser->main_wallet < $amount) {
                    throw new \RuntimeException('INSUFFICIENT');
                }

                // Create withdrawal without linking to specific earnings
                $withdrawal = AgentWithdrawal::create([
                    'agent_id' => $user->id,
                    'amount' => $amount,
                    'status' => 'pending',
                    'bank_name' => $bankDetails->bank_name,
                    'bank_code' => $bankDetails->bank_code,
                    'account_number' => $bankDetails->account_number,
                    'account_name' => $bankDetails->account_name,
                ]);

                $lockedUser->decrement('main_wallet', $amount);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT') {
                return JsonResponser::send(true, 'Insufficient wallet balance.', null, 422);
            }

            throw $e;
        }

        return JsonResponser::send(false, 'Withdrawal request submitted.', [
            'withdrawal' => $withdrawal,
            'wallet_balance' => (float) $user->fresh()->main_wallet,
        ], 200);
    }

    /**
     * Apply to become a Tier 1 delivery agent.
     *
     * No security deposit required — every agent can apply and start
     * fulfilling Tier 1 orders (below ₦10,000) once an admin approves
     * the application.
     */
    public function applyToBecomeDeliveryAgent(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        if ($user->is_delivery_agent) {
            return JsonResponser::send(true, 'You are already a delivery agent.', null, 400);
        }

        if ($user->delivery_agent_application_status === 'pending') {
            return JsonResponser::send(true, 'You already have an application in progress.', null, 400);
        }

        try {
            $user->update([
                'delivery_agent_application_status' => 'pending',
            ]);

            return JsonResponser::send(false, 'Delivery agent application submitted successfully. Wait for admin approval.', [
                'application_status' => 'pending',
            ], 200);
        } catch (\Exception $e) {
            return JsonResponser::send(true, 'Failed to submit application. Please try again.', null, 500);
        }
    }
             public function tierUpgradeEligibility(Request $request)
{
    $user = $request->user();
 
    if ($user->user_type !== 'agent') {
        return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
    }
 
    // Extract current tier from delivery_agent_tier field (format: "tier_1", "tier_2")
    $currentTier = 1;
    if ($user->delivery_agent_tier) {
        preg_match('/tier_(\d+)/', $user->delivery_agent_tier, $matches);
        $currentTier = $matches[1] ?? 1;
    }
 
    $completedDeliveries = Order::where('accepted_by', $user->id)
        ->where('status', 'delivered')
        ->count();
 
    // Get tier configurations from database
    $currentTierConfig = DeliveryTierSetting::getForTier($currentTier);
    $nextTierConfig = DeliveryTierSetting::getNextTier($currentTier);
 
    $requiredDeliveries = $nextTierConfig?->min_completed_deliveries ?? 0;
    $minDeposit = $nextTierConfig?->min_security_deposit ?? 0;
    $maxDeposit = $nextTierConfig?->max_security_deposit ?? 0;
    $nextTierMaxAmount = $nextTierConfig?->max_order_amount ?? 0;
 
    return JsonResponser::send(false, 'Eligibility loaded.', [
        'is_delivery_agent' => (bool) $user->is_delivery_agent,
        'current_tier' => $currentTier,
        'current_tier_name' => $currentTierConfig?->tier_name ?? "Tier {$currentTier}",
        'current_tier_max_amount' => (float) ($currentTierConfig?->max_order_amount ?? 0),
        'next_tier' => $nextTierConfig?->tier ?? null,
        'next_tier_name' => $nextTierConfig?->tier_name ?? null,
        'next_tier_max_amount' => (float) $nextTierMaxAmount,
        'completed_deliveries' => $completedDeliveries,
        'required_deliveries' => $requiredDeliveries,
        'eligible_for_upgrade' => $user->is_delivery_agent
            && $nextTierConfig
            && $completedDeliveries >= $requiredDeliveries,
        'tier_upgrade_status' => $user->tier_upgrade_status,
        'security_deposit_min' => (float) $minDeposit,
        'security_deposit_max' => (float) $maxDeposit,
    ], 200);
}
 
/**
 * 4. REPLACE the entire requestTierUpgrade() method with:
 */
 
public function requestTierUpgrade(Request $request)
{
    $user = $request->user();
 
    if ($user->user_type !== 'agent') {
        return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
    }
 
    if (!$user->is_delivery_agent) {
        return JsonResponser::send(true, 'You must be an approved delivery agent first.', null, 403);
    }
 
    // Extract current tier
    $currentTier = 1;
    if ($user->delivery_agent_tier) {
        preg_match('/tier_(\d+)/', $user->delivery_agent_tier, $matches);
        $currentTier = $matches[1] ?? 1;
    }
 
    $nextTierConfig = DeliveryTierSetting::getNextTier($currentTier);
    if (!$nextTierConfig) {
        return JsonResponser::send(true, 'No higher tier available for upgrade.', null, 422);
    }
 
    if ($user->tier_upgrade_status === 'pending') {
        return JsonResponser::send(true, 'You already have a tier upgrade request pending review.', null, 400);
    }
 
    $completedDeliveries = Order::where('accepted_by', $user->id)
        ->where('status', 'delivered')
        ->count();
 
    if ($completedDeliveries < $nextTierConfig->min_completed_deliveries) {
        return JsonResponser::send(true, sprintf(
            'You need at least %d completed deliveries to request upgrade to %s. You currently have %d.',
            $nextTierConfig->min_completed_deliveries,
            $nextTierConfig->tier_name,
            $completedDeliveries
        ), [
            'completed_deliveries' => $completedDeliveries,
            'required_deliveries' => $nextTierConfig->min_completed_deliveries,
        ], 422);
    }
 
    $validator = Validator::make($request->all(), [
        'security_deposit_amount' => [
            'required',
            'numeric',
            'min:' . $nextTierConfig->min_security_deposit,
            'max:' . $nextTierConfig->max_security_deposit,
        ],
    ]);
 
    if ($validator->fails()) {
        return JsonResponser::send(true, $validator->errors()->first(), $validator->errors(), 422);
    }
 
    $securityDepositAmount = round((float) $validator->validated()['security_deposit_amount'], 2);
 
    if ((float) $user->main_wallet < $securityDepositAmount) {
        return JsonResponser::send(true, 'Insufficient wallet balance to cover the security deposit.', null, 422);
    }
 
    try {
        DB::transaction(function () use ($user, $securityDepositAmount, $completedDeliveries) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
 
            if ((float) $lockedUser->main_wallet < $securityDepositAmount) {
                throw new \RuntimeException('INSUFFICIENT');
            }
 
            $lockedUser->decrement('main_wallet', $securityDepositAmount);
            $lockedUser->update([
                'security_wallet_deposit' => $securityDepositAmount,
                'tier_upgrade_status' => 'pending',
                'tier_upgrade_requested_at' => now(),
                'tier_upgrade_completed_deliveries_snapshot' => $completedDeliveries,
            ]);
        });
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === 'INSUFFICIENT') {
            return JsonResponser::send(true, 'Insufficient wallet balance to cover the security deposit.', null, 422);
        }
 
        throw $e;
    } catch (\Exception $e) {
        return JsonResponser::send(true, 'Failed to submit tier upgrade request. Please try again.', null, 500);
    }
 
    return JsonResponser::send(false, 'Tier upgrade request submitted. An admin will review your delivery history before approving.', [
        'tier_upgrade_status' => 'pending',
        'security_deposit_amount' => $securityDepositAmount,
        'wallet_balance' => (float) $user->fresh()->main_wallet,
    ], 200);
}
}