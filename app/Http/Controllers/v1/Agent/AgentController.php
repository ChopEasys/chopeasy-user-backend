<?php

namespace App\Http\Controllers\v1\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentBankDetail;
use App\Models\AgentCommissionSetting;
use App\Models\AgentCustomerNotificationPref;
use App\Models\AgentEarning;
use App\Models\AgentWithdrawal;
use App\Models\AgentWithdrawalLine;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\AgentCommissionService;
use App\Support\PaystackClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AgentController extends Controller
{
    public function dashboard(Request $request, AgentCommissionService $agentCommissionService)
    {
        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return JsonResponser::send(true, 'Access denied. Agent only.', null, 403);
        }

        $settings = AgentCommissionSetting::query()->first();
        if (!$settings) {
            $settings = AgentCommissionSetting::query()->create([
                'customer_percent' => 10,
                'vendor_percent' => 10,
                'rider_percent' => 10,
                'max_vendor_rider_payout_commissions' => 5,
            ]);
        }

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

        return JsonResponser::send(false, 'Dashboard loaded.', [
            'user' => [
                'id' => $user->id,
                'fullname' => $user->fullname,
                'email' => $user->email,
                'phoneno' => $user->phoneno,
                'user_type' => $user->user_type,
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
                'rider' => [
                    'link' => $mkLink(config('app.register_base_rider'), 'rider'),
                    'title' => 'Refer riders',
                    'description' => 'Earn ' . $settings->rider_percent . '% of the platform delivery profit (delivery fee minus rider payout) on each completed delivery by riders you referred (up to ' . $settings->max_vendor_rider_payout_commissions . ' payouts per rider).',
                ],
            ],
            'referral_code' => $ref,
            'referred_customers_count' => User::where('referred_by_agent_id', $user->id)->where('user_type', 'customer')->count(),
            'referred_vendors_count' => User::where('referred_by_agent_id', $user->id)->where('user_type', 'vendor')->count(),
            'referred_riders_count' => User::where('referred_by_agent_id', $user->id)->where('user_type', 'rider')->count(),
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
            'rider' => 'rider_payout',
        ];
        if (!isset($map[$type])) {
            return JsonResponser::send(true, 'Invalid type. Use customer, vendor, or rider.', null, 422);
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
        $lines = [];

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
}
