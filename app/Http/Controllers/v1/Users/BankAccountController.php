<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\AgentBankDetail;
use App\Models\AgentWithdrawal;
use App\Services\AutomaticPayoutService;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BankAccountController extends Controller
{
    protected array $allowedUserTypes = ['vendor', 'rider', 'agent'];

    protected function authorizedUser(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated user.',
            ], 401);
        }

        if (!in_array($user->user_type, $this->allowedUserTypes, true)) {
            return response()->json([
                'error' => 'Only vendors, riders, and agents can manage bank accounts.',
            ], 403);
        }

        return null;
    }

    protected function bankRelationName(string $userType): ?string
    {
        return match ($userType) {
            'vendor' => 'vendorBankDetails',
            'rider' => 'riderBankDetails',
            'agent' => 'agentBankDetails',
            default => null,
        };
    }

    protected function bankTableName(string $userType): ?string
    {
        return match ($userType) {
            'vendor' => 'vendor_bank_details',
            'rider' => 'rider_bank_details',
            'agent' => 'agent_bank_details',
            default => null,
        };
    }

    protected function bankDetailsRelation($user): ?HasOne
    {
        $relationName = $this->bankRelationName((string) $user->user_type);

        return $relationName ? $user->{$relationName}() : null;
    }

    protected function bankDetailsRecord($user)
    {
        $relationName = $this->bankRelationName((string) $user->user_type);

        return $relationName ? $user->{$relationName} : null;
    }

    protected function migrateLegacyBankDetails($user)
    {
        if (!in_array($user->user_type, ['vendor', 'rider'], true)) {
            return $this->bankDetailsRecord($user);
        }

        $currentBankDetails = $this->bankDetailsRecord($user);
        $legacyBankDetails = $user->agentBankDetails;

        if (!$currentBankDetails && $legacyBankDetails && ($relation = $this->bankDetailsRelation($user))) {
            $currentBankDetails = $relation->create([
                'bank_name' => $legacyBankDetails->bank_name,
                'bank_code' => $legacyBankDetails->bank_code,
                'account_number' => $legacyBankDetails->account_number,
                'account_name' => $legacyBankDetails->account_name,
                'recipient_code' => null,
            ]);
        }

        if ($legacyBankDetails) {
            $user->agentBankDetails()->delete();
        }

        return $currentBankDetails;
    }

    protected function basePaystackUrl(): string
    {
        return rtrim(env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'), '/');
    }

    protected function paystackSecretKey(): ?string
    {
        $secretKey = env('PAYSTACK_SECRET_KEY');

        return is_string($secretKey) && trim($secretKey) !== '' ? trim($secretKey) : null;
    }

    protected function validatePaystackConfig(): ?JsonResponse
    {
        if (!$this->paystackSecretKey()) {
            return response()->json([
                'error' => 'Paystack secret key not configured.',
            ], 500);
        }

        return null;
    }

    protected function resolveAccountWithPaystack(string $bankCode, string $accountNumber): array
    {
        $response = Http::withToken($this->paystackSecretKey())
            ->get($this->basePaystackUrl() . '/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

        if (!$response->ok() || $response->json('status') !== true) {
            $message = $response->json('message') ?? 'Unable to resolve account details.';

            throw new \RuntimeException($message);
        }

        $payload = $response->json('data') ?? [];

        return [
            'bank_name' => trim((string) ($payload['bank_name'] ?? '')),
            'bank_code' => $bankCode,
            'account_number' => $accountNumber,
            'account_name' => trim((string) ($payload['account_name'] ?? '')),
        ];
    }

    protected function bankAccountPayload($bankDetails): ?array
    {
        if (!$bankDetails) {
            return null;
        }

        return [
            'bank_name' => $bankDetails->bank_name,
            'bank_code' => $bankDetails->bank_code,
            'account_number' => $bankDetails->account_number,
            'account_name' => $bankDetails->account_name,
        ];
    }

    public function show(Request $request): JsonResponse
    {
        if ($authError = $this->authorizedUser($request)) {
            return $authError;
        }

        $user = $request->user();
        $bankDetails = $this->migrateLegacyBankDetails($user);

        return response()->json([
            'bank_account' => $this->bankAccountPayload($bankDetails),
            'bank_account_table' => $this->bankTableName((string) $user->user_type),
            'user' => [
                'fullname' => $user->fullname,
                'email' => $user->email,
                'phoneno' => $user->phoneno,
                'user_type' => $user->user_type,
            ],
        ]);
    }

    public function listBanks(Request $request): JsonResponse
    {
        if ($authError = $this->authorizedUser($request)) {
            return $authError;
        }

        if ($configError = $this->validatePaystackConfig()) {
            return $configError;
        }

        $response = Http::withToken($this->paystackSecretKey())
            ->get($this->basePaystackUrl() . '/bank', [
                'country' => 'nigeria',
            ]);

        if (!$response->ok() || $response->json('status') !== true) {
            return response()->json([
                'error' => $response->json('message') ?? 'Unable to load banks.',
            ], 422);
        }

        $banks = collect($response->json('data') ?? [])
            ->map(fn ($bank) => [
                'name' => trim((string) ($bank['name'] ?? '')),
                'code' => trim((string) ($bank['code'] ?? '')),
            ])
            ->filter(fn ($bank) => $bank['name'] !== '' && $bank['code'] !== '')
            ->sortBy('name')
            ->values();

        return response()->json([
            'banks' => $banks,
        ]);
    }

    public function resolve(Request $request): JsonResponse
    {
        if ($authError = $this->authorizedUser($request)) {
            return $authError;
        }

        if ($configError = $this->validatePaystackConfig()) {
            return $configError;
        }

        $validator = Validator::make($request->all(), [
            'bank_code' => 'required|string|max:50',
            'account_number' => ['required', 'regex:/^\d{10}$/'],
        ], [
            'account_number.regex' => 'Account number must be 10 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
            ], 422);
        }

        $payload = $validator->validated();

        try {
            $resolved = $this->resolveAccountWithPaystack(
                trim($payload['bank_code']),
                trim($payload['account_number'])
            );

            return response()->json($resolved);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        return $this->upsertBankAccount($request, false);
    }

    public function update(Request $request): JsonResponse
    {
        return $this->upsertBankAccount($request, true);
    }

    protected function upsertBankAccount(Request $request, bool $updating): JsonResponse
    {
        if ($authError = $this->authorizedUser($request)) {
            return $authError;
        }

        if ($configError = $this->validatePaystackConfig()) {
            return $configError;
        }

        $validator = Validator::make($request->all(), [
            'bank_code' => 'required|string|max:50',
            'account_number' => ['required', 'regex:/^\d{10}$/'],
        ], [
            'account_number.regex' => 'Account number must be 10 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
            ], 422);
        }

        $payload = $validator->validated();
        $user = $request->user();
        $relation = $this->bankDetailsRelation($user);

        if (!$relation) {
            return response()->json([
                'error' => 'No bank account storage is configured for this user type.',
            ], 422);
        }

        try {
            $resolved = $this->resolveAccountWithPaystack(
                trim($payload['bank_code']),
                trim($payload['account_number'])
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 422);
        }

        $existing = $this->bankDetailsRecord($user);
        $bankDetails = $relation->updateOrCreate(
            ['user_id' => $user->id],
            array_merge($resolved, [
                'recipient_code' => (
                    $existing &&
                    $existing->bank_code === $resolved['bank_code'] &&
                    $existing->account_number === $resolved['account_number'] &&
                    $existing->account_name === $resolved['account_name']
                ) ? $existing->recipient_code : null,
            ])
        );

        if ($user->user_type === 'agent') {
            AgentBankDetail::where('user_id', $user->id)
                ->where('id', '!=', $bankDetails->id)
                ->delete();
        }

        $bankDetails->refresh();
        $user->unsetRelation('agentBankDetails');

        $this->migrateLegacyBankDetails($user->fresh());

        if (in_array($user->user_type, ['vendor', 'rider'], true)) {
            try {
                app(AutomaticPayoutService::class)->retryPendingPayouts($user->fresh());
            } catch (\Throwable $exception) {
                Log::warning('Failed to retry automatic payouts after bank update.', [
                    'user_id' => $user->id,
                    'user_type' => $user->user_type,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => $updating
                ? 'Bank account updated successfully!'
                : 'Bank account saved successfully!',
            'bank_account' => $this->bankAccountPayload($bankDetails),
            'bank_account_table' => $this->bankTableName((string) $user->user_type),
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        if ($authError = $this->authorizedUser($request)) {
            return $authError;
        }

        $user = $request->user();

        if ($user->user_type !== 'agent') {
            return response()->json([
                'error' => 'Manual withdrawals are no longer available for vendors or riders. Payouts are sent automatically after customer confirmation.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()->first(),
            ], 422);
        }

        $bankDetails = $user->agentBankDetails;

        if (!$bankDetails) {
            return response()->json([
                'error' => 'Please add your bank account before withdrawing.',
            ], 422);
        }

        $amount = (float) $validator->validated()['amount'];

        if ((float) $user->main_wallet < $amount) {
            return response()->json([
                'error' => 'Insufficient wallet balance.',
            ], 422);
        }

        $withdrawal = null;

        DB::transaction(function () use ($user, $bankDetails, $amount, &$withdrawal) {
            $withdrawal = AgentWithdrawal::create([
                'agent_id' => $user->id,
                'amount' => $amount,
                'status' => 'pending',
                'bank_name' => $bankDetails->bank_name,
                'bank_code' => $bankDetails->bank_code,
                'account_number' => $bankDetails->account_number,
                'account_name' => $bankDetails->account_name,
            ]);

            $user->decrement('main_wallet', $amount);
        });

        return response()->json([
            'message' => 'Withdrawal request submitted successfully!',
            'withdrawal' => $withdrawal,
            'wallet_balance' => (float) $user->fresh()->main_wallet,
        ]);
    }
}
