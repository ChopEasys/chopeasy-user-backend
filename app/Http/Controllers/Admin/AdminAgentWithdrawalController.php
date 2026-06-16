<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentEarning;
use App\Models\AgentWithdrawal;
use App\Models\AgentBankDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdminAgentWithdrawalController extends Controller
{
    protected function basePaystackUrl(): string
    {
        return rtrim(env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'), '/');
    }

    protected function paystackSecretKey(): ?string
    {
        $secretKey = env('PAYSTACK_SECRET_KEY');
        return is_string($secretKey) && trim($secretKey) !== '' ? trim($secretKey) : null;
    }

    protected function paystackConfigured(): bool
    {
        return $this->paystackSecretKey() !== null;
    }

    protected function createTransferRecipient(AgentBankDetail $bankDetails): string
    {
        $response = Http::withToken($this->paystackSecretKey())
            ->post($this->basePaystackUrl() . '/transferrecipient', [
                'type' => 'nuban',
                'name' => $bankDetails->account_name,
                'account_number' => $bankDetails->account_number,
                'bank_code' => $bankDetails->bank_code,
                'currency' => 'NGN',
            ]);

        if (!$response->ok() || $response->json('status') !== true) {
            throw new \RuntimeException($response->json('message') ?? 'Unable to create transfer recipient.');
        }

        $recipientCode = trim((string) $response->json('data.recipient_code'));

        if ($recipientCode === '') {
            throw new \RuntimeException('Transfer recipient code was not returned by Paystack.');
        }

        $bankDetails->forceFill(['recipient_code' => $recipientCode])->save();

        return $recipientCode;
    }

    protected function initiateTransfer(string $recipientCode, float $amount, string $reason): array
    {
        $response = Http::withToken($this->paystackSecretKey())
            ->post($this->basePaystackUrl() . '/transfer', [
                'source' => 'balance',
                'amount' => (int) round($amount * 100),
                'recipient' => $recipientCode,
                'reason' => $reason,
            ]);

        if (!$response->ok() || $response->json('status') !== true) {
            throw new \RuntimeException($response->json('message') ?? 'Unable to initiate transfer.');
        }

        return $response->json('data') ?? [];
    }

    protected function normalizedTransferStatus(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'success' => 'paid',
            'pending', 'otp', 'received', 'queued', 'processing' => 'processing',
            default => 'processing',
        };
    }

    /**
     * List agent withdrawal requests
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 20);
        $status = $request->query('status');
        $search = $request->query('search');

        $query = AgentWithdrawal::with(['agent:id,fullname,email,main_wallet', 'lines'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('bank_name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhereHas('agent', function ($q3) use ($search) {
                            $q3->where('fullname', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('created_at');

        $withdrawals = $query->paginate($perPage);

        $formatted = $withdrawals->map(fn($w) => $this->formatWithdrawal($w));

        return response()->json([
            'data' => $formatted,
            'pagination' => [
                'currentPage' => $withdrawals->currentPage(),
                'lastPage' => $withdrawals->lastPage(),
                'perPage' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    /**
     * List approved withdrawal history
     */
    public function history(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 20);
        $search = $request->query('search');

        $query = AgentWithdrawal::with(['agent:id,fullname,email,main_wallet', 'lines'])
            ->whereIn('status', ['approved', 'paid'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('bank_name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhereHas('agent', function ($q3) use ($search) {
                            $q3->where('fullname', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('updated_at');

        $withdrawals = $query->paginate($perPage);

        $formatted = $withdrawals->map(fn($w) => $this->formatWithdrawal($w));

        return response()->json([
            'data' => $formatted,
            'pagination' => [
                'currentPage' => $withdrawals->currentPage(),
                'lastPage' => $withdrawals->lastPage(),
                'perPage' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    /**
     * Approve a pending withdrawal request
     */
    public function approve(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $withdrawal = AgentWithdrawal::with('agent:id,fullname,email,main_wallet')
                ->lockForUpdate()
                ->find($id);

            if (!$withdrawal) {
                return response()->json(['error' => 'Withdrawal not found'], 404);
            }

            if ($withdrawal->status !== 'pending') {
                return response()->json(['error' => 'Withdrawal already processed'], 422);
            }

            if (!$this->paystackConfigured()) {
                $withdrawal->status = 'approved';
                $withdrawal->failure_reason = 'Paystack secret key is not configured. Manual transfer required.';
                $withdrawal->save();

                return response()->json([
                    'data' => $this->formatWithdrawal($withdrawal->fresh('agent')),
                ]);
            }

            $bankDetails = AgentBankDetail::where('user_id', $withdrawal->agent_id)->first();
            if (!$bankDetails) {
                $withdrawal->status = 'approved';
                $withdrawal->failure_reason = 'No bank details found for agent. Manual transfer required.';
                $withdrawal->save();

                return response()->json([
                    'data' => $this->formatWithdrawal($withdrawal->fresh('agent')),
                ]);
            }

            try {
                $recipientCode = filled($bankDetails->recipient_code)
                    ? trim((string) $bankDetails->recipient_code)
                    : $this->createTransferRecipient($bankDetails);

                $transfer = $this->initiateTransfer(
                    $recipientCode,
                    (float) $withdrawal->amount,
                    sprintf('Agent withdrawal for agent %s', $withdrawal->agent?->fullname ?? $withdrawal->agent_id)
                );

                $status = $this->normalizedTransferStatus($transfer['status'] ?? null);

                $withdrawal->fill([
                    'recipient_code' => $recipientCode,
                    'transfer_code' => $transfer['transfer_code'] ?? null,
                    'transfer_reference' => $transfer['reference'] ?? null,
                    'status' => $status,
                    'failure_reason' => $status === 'paid' ? null : 'Transfer initiated but not yet confirmed',
                    'paid_at' => $status === 'paid' ? now() : null,
                ])->save();
            } catch (\Throwable $exception) {
                Log::warning('Agent withdrawal transfer failed.', [
                    'withdrawal_id' => $withdrawal->id,
                    'agent_id' => $withdrawal->agent_id,
                    'error' => $exception->getMessage(),
                ]);

                $withdrawal->fill([
                    'status' => 'approved',
                    'failure_reason' => $exception->getMessage(),
                    'paid_at' => null,
                ])->save();
            }

            return response()->json([
                'data' => $this->formatWithdrawal($withdrawal->fresh('agent')),
            ]);
        });
    }

    /**
     * Reject a pending withdrawal and refund the wallet
     */
    public function reject(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $withdrawal = AgentWithdrawal::with('agent:id,fullname,email,main_wallet')
                ->lockForUpdate()
                ->find($id);

            if (!$withdrawal) {
                return response()->json(['error' => 'Withdrawal not found'], 404);
            }

            if ($withdrawal->status !== 'pending') {
                return response()->json(['error' => 'Withdrawal already processed'], 422);
            }

            if (!$withdrawal->agent) {
                return response()->json(['error' => 'Agent not found for withdrawal'], 422);
            }

            AgentEarning::where('withdrawal_id', $withdrawal->id)->update(['withdrawal_id' => null]);
            $withdrawal->lines()->delete();

            $withdrawal->status = 'rejected';
            $withdrawal->save();

            $withdrawal->agent->increment('main_wallet', $withdrawal->amount);

            return response()->json([
                'data' => $this->formatWithdrawal($withdrawal->fresh('agent')),
            ]);
        });
    }

    private function formatWithdrawal(AgentWithdrawal $withdrawal): array
    {
        $agent = $withdrawal->agent;
        $withdrawal->loadMissing('lines');

        return [
            'id' => (string) $withdrawal->id,
            'agent_id' => (string) $withdrawal->agent_id,
            'agent_name' => $agent?->fullname ?? 'Unknown',
            'agent_email' => $agent?->email ?? '',
            'agent_wallet' => $agent ? (float) $agent->main_wallet : null,
            'amount' => (float) $withdrawal->amount,
            'status' => $withdrawal->status,
            'bank_name' => $withdrawal->bank_name,
            'bank_code' => $withdrawal->bank_code,
            'account_number' => $withdrawal->account_number,
            'account_name' => $withdrawal->account_name,
            'created_at' => $withdrawal->created_at?->format('Y-m-d H:i') ?? '',
            'approved_at' => $withdrawal->updated_at?->format('Y-m-d H:i') ?? '',
            'linked_commissions' => $withdrawal->lines->map(fn ($l) => [
                'order_number' => $l->order_number,
                'earning_type' => $l->earning_type,
                'amount' => (float) $l->amount,
            ])->values()->all(),
        ];
    }
}
