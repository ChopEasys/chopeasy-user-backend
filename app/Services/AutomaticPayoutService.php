<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RiderPayout;
use App\Models\User;
use App\Models\VendorPayout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutomaticPayoutService
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

    protected function bankDetailsForUser(User $user): ?Model
    {
        return match ($user->user_type) {
            'vendor' => $user->vendorBankDetails,
            'rider' => $user->riderBankDetails,
            'agent' => $user->agentBankDetails,
            default => null,
        };
    }

    protected function decodeProductSnapshot($snapshot): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        $decoded = json_decode((string) $snapshot, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function decodePricingBreakdown($breakdown): array
    {
        if (is_array($breakdown)) {
            return $breakdown;
        }

        $decoded = json_decode((string) $breakdown, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Rider receives (weight fee minus platform % of weight fee) + distance zone payout.
     * Order base fee and platform share of weight fee stay with the platform — not paid to riders.
     * Recomputed from pricing_breakdown so legacy orders still pay the correct net amount.
     */
    protected function resolveRiderPayoutAmount(Order $order): float
    {
        $breakdown = $this->decodePricingBreakdown($order->pricing_breakdown);
        if ($breakdown === []) {
            return max(0, (float) ($order->rider_payout ?? 0));
        }

        $payoutBlock = is_array($breakdown['payout_breakdown'] ?? null)
            ? $breakdown['payout_breakdown']
            : [];

        $weightFee = (float) ($breakdown['weight_fee'] ?? $breakdown['weight_service_fee'] ?? 0);
        $platformPct = max(0, min(100, (float) ($breakdown['weight_platform_percentage'] ?? 0)));

        $weightPlatformTake = array_key_exists('weight_fee_platform_take', $breakdown)
            ? (float) $breakdown['weight_fee_platform_take']
            : round($weightFee * ($platformPct / 100), 2);
        $weightRiderShare = array_key_exists('weight_fee_rider_share', $breakdown)
            ? (float) $breakdown['weight_fee_rider_share']
            : round(max($weightFee - $weightPlatformTake, 0), 2);

        $distanceFee = (float) ($breakdown['distance_fee'] ?? $breakdown['distance_charge_total'] ?? 0);
        $distancePayout = array_key_exists('rider_distance_payout', $payoutBlock)
            ? (float) $payoutBlock['rider_distance_payout']
            : $distanceFee;

        return max(0, round($weightRiderShare + $distancePayout, 2));
    }

    protected function vendorSettlementForOrder(Order $order, float $grossAmount): array
    {
        $pricingBreakdown = $this->decodePricingBreakdown($order->pricing_breakdown);
        $payoutBreakdown = is_array($pricingBreakdown['payout_breakdown'] ?? null)
            ? $pricingBreakdown['payout_breakdown']
            : [];

        $takePercent = (float) ($payoutBreakdown['vendor_take_percent']
            ?? $pricingBreakdown['vendor_take_percent']
            ?? 0);
        $takeAmount = $grossAmount > 0
            ? round($grossAmount * $takePercent / 100, 2)
            : 0.0;
        $netAmount = max(round($grossAmount - $takeAmount, 2), 0);

        return [
            'gross_amount' => round($grossAmount, 2),
            'take_percent' => round($takePercent, 2),
            'take_amount' => round($takeAmount, 2),
            'net_amount' => round($netAmount, 2),
        ];
    }

    protected function buildVendorPayoutMap(Order $order): Collection
    {
        $totals = collect();

        foreach ($order->items as $item) {
            $snapshot = $this->decodeProductSnapshot($item->product_snapshot);
            $vendorUnitPrice = (float) ($snapshot['vendor_price'] ?? $snapshot['price'] ?? $item->price_at_order ?? 0);
            $amount = round($vendorUnitPrice * (int) ($item->quantity ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            $vendorOrder = collect($item->vendorOrders ?? [])->first();
            $vendorId = (int) ($vendorOrder->vendor_id ?? 0);

            if ($vendorId <= 0) {
                continue;
            }

            $existing = $totals->get($vendorId, [
                'vendor' => $vendorOrder->vendor ?? User::find($vendorId),
                'amount' => 0.0,
            ]);

            $existing['amount'] = round(((float) $existing['amount']) + $amount, 2);
            $totals->put($vendorId, $existing);
        }

        return $totals;
    }

    protected function createTransferRecipient(Model $bankDetails): string
    {
        $response = Http::withToken($this->paystackSecretKey())
            ->post($this->basePaystackUrl() . '/transferrecipient', [
                'type' => 'nuban',
                'name' => $bankDetails->account_name,
                'account_number' => $bankDetails->account_number,
                'bank_code' => $bankDetails->bank_code,
                'currency' => 'NGN',
            ]);

        Log::info('Paystack createTransferRecipient response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ]);

        // FIX: Paystack returns 201 on create, and Laravel's ->ok() only matches
        // exactly 200. ->successful() covers the whole 2xx range.
        if (!$response->successful() || $response->json('status') !== true) {
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

        Log::info('Paystack initiateTransfer response', [
            'recipient_code' => $recipientCode,
            'amount' => $amount,
            'status' => $response->status(),
            'body' => $response->body(),
            'json' => $response->json(),
        ]);

        // FIX: same ->ok() vs ->successful() issue as above.
        if (!$response->successful() || $response->json('status') !== true) {
            throw new \RuntimeException($response->json('message') ?? 'Unable to initiate transfer.');
        }

        return $response->json('data') ?? [];
    }

    protected function normalizedTransferStatus(?string $status): string
    {
        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            'success' => 'paid',
            'pending', 'otp', 'received', 'queued', 'processing' => 'processing',
            // FIX: a declined/reversed transfer must surface as failed, not
            // silently sit as "processing" forever.
            'failed', 'reversed' => 'failed',
            default => $this->logUnrecognizedTransferStatus($normalized),
        };
    }

    private function logUnrecognizedTransferStatus(string $normalized): string
    {
        Log::warning('Unrecognized Paystack transfer status, defaulting to processing.', [
            'status' => $normalized,
        ]);

        return 'processing';
    }

    protected function fillBankFields(Model $payout, ?Model $bankDetails): Model
    {
        return $payout->fill([
            'bank_name' => $bankDetails?->bank_name,
            'bank_code' => $bankDetails?->bank_code,
            'account_number' => $bankDetails?->account_number,
            'account_name' => $bankDetails?->account_name,
            'recipient_code' => $bankDetails?->recipient_code,
        ]);
    }

    protected function attemptPayout(Model $payout, User $user, string $reason): Model
    {
        $bankDetails = $this->bankDetailsForUser($user);
        $this->fillBankFields($payout, $bankDetails);

        if (!$bankDetails) {
            $payout->fill([
                'status' => 'pending_bank_details',
                'failure_reason' => 'No bank details saved for automatic payout.',
                'paid_at' => null,
            ])->save();

            return $payout;
        }

        if (!$this->paystackConfigured()) {
            $payout->fill([
                'status' => 'failed',
                'failure_reason' => 'Paystack secret key is not configured.',
                'paid_at' => null,
            ])->save();

            return $payout;
        }

        try {
            $recipientCode = filled($bankDetails->recipient_code)
                ? trim((string) $bankDetails->recipient_code)
                : $this->createTransferRecipient($bankDetails);

            $transfer = $this->initiateTransfer($recipientCode, (float) $payout->amount, $reason);
            $status = $this->normalizedTransferStatus($transfer['status'] ?? null);

            $payout->fill([
                'recipient_code' => $recipientCode,
                'transfer_code' => $transfer['transfer_code'] ?? null,
                'transfer_reference' => $transfer['reference'] ?? null,
                'status' => $status,
                'failure_reason' => null,
                'paid_at' => $status === 'paid' ? now() : null,
            ])->save();
        } catch (\Throwable $exception) {
            Log::warning('Automatic payout failed.', [
                'user_id' => $user->id,
                'user_type' => $user->user_type,
                'payout_id' => $payout->id,
                'error' => $exception->getMessage(),
            ]);

            $payout->fill([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'paid_at' => null,
            ])->save();
        }

        return $payout;
    }

    protected function processVendorPayout(Order $order, User $vendor, float $amount): VendorPayout
    {
        $payout = VendorPayout::firstOrNew([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
        ]);

        if (in_array($payout->status, ['paid', 'processing'], true)) {
            return $payout;
        }

        $payout->amount = round($amount, 2);
        if (!$payout->exists) {
            $payout->status = 'pending';
        }
        $payout->save();

        return $this->attemptPayout(
            $payout,
            $vendor->loadMissing('vendorBankDetails'),
            sprintf('Vendor payout for order %s', $order->order_number ?: $order->id)
        );
    }

    protected function processRiderPayout(Order $order, User $rider, float $amount): RiderPayout
    {
        $payout = RiderPayout::firstOrNew([
            'order_id' => $order->id,
            'rider_id' => $rider->id,
        ]);

        if (in_array($payout->status, ['paid', 'processing'], true)) {
            return $payout;
        }

        $payout->amount = round($amount, 2);
        if (!$payout->exists) {
            $payout->status = 'pending';
        }
        $payout->save();

        return $this->attemptPayout(
            $payout,
            $rider->loadMissing('riderBankDetails'),
            sprintf('Rider payout for order %s', $order->order_number ?: $order->id)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mapVendorPayoutsToResponse(array $vendorPayoutEntries): array
    {
        return collect($vendorPayoutEntries)->map(function (array $entry) {
            /** @var VendorPayout $payout */
            $payout = $entry['payout'];
            $settlement = $entry['settlement'];

            return [
                'id' => $payout->id,
                'vendor_id' => $payout->vendor_id,
                'order_id' => $payout->order_id,
                'amount' => (float) $payout->amount,
                'gross_amount' => (float) $settlement['gross_amount'],
                'vendor_take_percent' => (float) $settlement['take_percent'],
                'vendor_take_amount' => (float) $settlement['take_amount'],
                'status' => $payout->status,
                'failure_reason' => $payout->failure_reason,
                'transfer_reference' => $payout->transfer_reference,
            ];
        })->values()->all();
    }

    /**
     * Vendor net payouts when a rider accepts the order (pickup handoff).
     *
     * @return list<array<string, mixed>>
     */
    public function processVendorPayoutsForOrder(Order $order): array
    {
        $order->loadMissing(['items.vendorOrders.vendor', 'user']);

        $vendorPayouts = [];
        foreach ($this->buildVendorPayoutMap($order) as $entry) {
            $vendor = $entry['vendor'] ?? null;
            $amount = (float) ($entry['amount'] ?? 0);
            $settlement = $this->vendorSettlementForOrder($order, $amount);

            if (!$vendor instanceof User || $settlement['net_amount'] <= 0) {
                continue;
            }

            $payout = $this->processVendorPayout($order, $vendor, $settlement['net_amount']);
            $vendorPayouts[] = [
                'payout' => $payout,
                'settlement' => $settlement,
            ];
        }

        return $this->mapVendorPayoutsToResponse($vendorPayouts);
    }

    /**
     * Rider payout when the customer confirms receipt.
     *
     * @return array<string, mixed>|null
     */
    public function processRiderPayoutForOrder(Order $order): ?array
    {
        $order->loadMissing(['items.vendorOrders.vendor', 'user']);

        $resolvedRiderAmount = $this->resolveRiderPayoutAmount($order);
        if ($resolvedRiderAmount > 0 && abs($resolvedRiderAmount - (float) ($order->rider_payout ?? 0)) > 0.009) {
            $order->update(['rider_payout' => $resolvedRiderAmount]);
            $order->refresh();
        }

        if ((int) ($order->accepted_by ?? 0) <= 0 || $resolvedRiderAmount <= 0) {
            return null;
        }

        $rider = User::find((int) $order->accepted_by);
        if (!$rider instanceof User) {
            return null;
        }

        $riderPayout = $this->processRiderPayout($order, $rider, $resolvedRiderAmount);

        return [
            'id' => $riderPayout->id,
            'rider_id' => $riderPayout->rider_id,
            'order_id' => $riderPayout->order_id,
            'amount' => (float) $riderPayout->amount,
            'status' => $riderPayout->status,
            'failure_reason' => $riderPayout->failure_reason,
            'transfer_reference' => $riderPayout->transfer_reference,
        ];
    }

    /**
     * Legacy / safety: run vendor payouts on customer confirm only if none exist yet
     * (e.g. order was accepted before vendor-on-rider-accept was deployed).
     *
     * @return list<array<string, mixed>>
     */
    public function processVendorPayoutsIfOutstanding(Order $order): array
    {
        if (VendorPayout::where('order_id', $order->id)->exists()) {
            return [];
        }

        return $this->processVendorPayoutsForOrder($order);
    }

    /**
     * Full settlement (vendor + rider). Prefer split entrypoints for production flow.
     *
     * @return array{vendor_payouts: list<array<string, mixed>>, rider_payout: array<string, mixed>|null}
     */
    public function processOrderPayouts(Order $order): array
    {
        return [
            'vendor_payouts' => $this->processVendorPayoutsForOrder($order),
            'rider_payout' => $this->processRiderPayoutForOrder($order),
        ];
    }

    public function retryPendingPayouts(User $user): void
    {
        if ($user->user_type === 'vendor') {
            VendorPayout::with('order')
                ->where('vendor_id', $user->id)
                ->whereIn('status', ['pending', 'pending_bank_details', 'failed'])
                ->get()
                ->each(fn (VendorPayout $payout) => $this->attemptPayout(
                    $payout,
                    $user->fresh()->loadMissing('vendorBankDetails'),
                    sprintf('Vendor payout for order %s', $payout->order?->order_number ?: $payout->order_id)
                ));
        }

        if ($user->user_type === 'rider') {
            RiderPayout::with('order')
                ->where('rider_id', $user->id)
                ->whereIn('status', ['pending', 'pending_bank_details', 'failed'])
                ->get()
                ->each(fn (RiderPayout $payout) => $this->attemptPayout(
                    $payout,
                    $user->fresh()->loadMissing('riderBankDetails'),
                    sprintf('Rider payout for order %s', $payout->order?->order_number ?: $payout->order_id)
                ));
        }
    }
}