<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Transaction;

class PaymentController extends Controller
{
    /**
     * Step 1: Initialize Paystack payment
     */
    public function initialize(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $amountInKobo = $request->amount * 100;
        $user = $request->user();
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
            ->post(env('PAYSTACK_PAYMENT_URL') . '/transaction/initialize', [
                'email'        => $user->email,
                'amount'       => $amountInKobo,
                'callback_url' => url('/api/v1/payment/callback'),
                'metadata'     => [
                    'cancel_action' => "{$frontendUrl}/payment-failed",
                    'user_id'       => $user->id,
                ],
            ]);

        return response()->json($response->json());
    }

    /**
     * Step 2: Paystack callback — verify, credit wallet, redirect to frontend
     */
    public function callback(Request $request)
    {
        $reference = $request->query('reference') ?? $request->query('trxref');
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        if (!$reference) {
            return redirect()->away("{$frontendUrl}/payment-failed");
        }

        $transaction = $this->creditDepositFromReference($reference);

        if ($transaction) {
            return redirect()->away("{$frontendUrl}/payment-success?reference={$reference}");
        }

        return redirect()->away("{$frontendUrl}/payment-failed?reference={$reference}");
    }

    /**
     * Step 3: Verify payment (public — frontend calls after Paystack redirect)
     */
    public function verify(Request $request)
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $request->reference;

        $existingTransaction = Transaction::where('reference', $reference)
            ->where('type', 'deposit')
            ->first();

        if ($existingTransaction) {
            $user = User::find($existingTransaction->user_id);

            return response()->json([
                'error'       => 'false',
                'message'     => 'Payment successful, wallet credited',
                'amount_paid' => $existingTransaction->amount,
                'main_wallet' => $user ? $user->main_wallet : null,
            ]);
        }

        $transaction = $this->creditDepositFromReference($reference);

        if ($transaction) {
            $user = User::find($transaction->user_id);

            return response()->json([
                'error'       => 'false',
                'message'     => 'Payment successful, wallet credited',
                'amount_paid' => $transaction->amount,
                'main_wallet' => $user ? $user->main_wallet : null,
            ]);
        }

        $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
            ->get(env('PAYSTACK_PAYMENT_URL') . "/transaction/verify/{$reference}")
            ->json();

        return response()->json([
            'message' => 'Payment failed or not yet processed',
            'data'    => $response,
        ], 400);
    }

    /**
     * Step 4: Paystack Webhook Handler
     */
    public function webhook(Request $request)
    {
        $signature = $request->header('x-paystack-signature');

        if (!$signature) {
            return response()->json(['message' => 'No signature provided'], 400);
        }

        $computedSignature = hash_hmac('sha512', $request->getContent(), env('PAYSTACK_SECRET_KEY'));

        if (!hash_equals($signature, $computedSignature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $input = $request->all();
        $event = $input['event'] ?? null;

        if ($event === 'charge.success') {
            $data = $input['data'] ?? null;

            if ($data && ($data['status'] ?? null) === 'success') {
                $this->creditDepositFromPaystackData($data);
            }
        }

        return response()->json(['message' => 'Webhook received'], 200);
    }

    /**
     * Verify with Paystack and credit the user's wallet (idempotent).
     */
    private function creditDepositFromReference(string $reference): ?Transaction
    {
        $existing = Transaction::where('reference', $reference)
            ->where('type', 'deposit')
            ->first();

        if ($existing) {
            return $existing;
        }

        $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
            ->get(env('PAYSTACK_PAYMENT_URL') . "/transaction/verify/{$reference}")
            ->json();

        if (!($response['status'] ?? false) || ($response['data']['status'] ?? null) !== 'success') {
            return null;
        }

        return $this->creditDepositFromPaystackData($response['data']);
    }

    /**
     * Credit wallet from Paystack transaction data. Returns null if user not found.
     */
    private function creditDepositFromPaystackData(array $data): ?Transaction
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) {
            return null;
        }

        $existing = Transaction::where('reference', $reference)
            ->where('type', 'deposit')
            ->first();

        if ($existing) {
            return $existing;
        }

        $amount = ($data['amount'] ?? 0) / 100;
        $email = $data['customer']['email'] ?? null;
        $userId = $data['metadata']['user_id'] ?? null;

        $user = $userId ? User::find($userId) : null;
        if (!$user && $email) {
            $user = User::where('email', $email)->first();
        }

        if (!$user) {
            return null;
        }

        $user->increment('main_wallet', $amount);

        return Transaction::create([
            'user_id'            => $user->id,
            'order_id'           => null,
            'type'               => 'deposit',
            'source_wallet'      => 'main_wallet',
            'destination_wallet' => 'main_wallet',
            'amount'             => $amount,
            'reference'          => $reference,
            'status'             => 'successful',
            'description'        => 'Wallet top up via Paystack',
        ]);
    }
}
