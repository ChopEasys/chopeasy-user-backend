<?php

namespace App\Jobs;

use App\Constants\PushNotificationType;
use App\Models\Order;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\PushNotificationFallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The backoff intervals between retries (seconds).
     * 5-minute intervals for each retry.
     */
    public array $backoff = [300, 300, 300];

    public function __construct(
        public int $userId,
        public string $type,
        public array $payload
    ) {
    }

    /**
     * Execute the job: send push notification to all active subscriptions for the user.
     */
    public function handle(): void
    {
        // 0. For deduction reminders, check if the plan is still active before sending (Requirement 5.4)
        if ($this->type === PushNotificationType::DEDUCTION_REMINDER && isset($this->payload['plan_id'])) {
            $plan = Order::find($this->payload['plan_id']);

            if (!$plan || $plan->payment_status === 'paid' || (float) $plan->remaining_amount <= 0) {
                Log::info('Deduction reminder suppressed: plan is no longer active', [
                    'user_id' => $this->userId,
                    'plan_id' => $this->payload['plan_id'],
                    'payment_status' => $plan?->payment_status,
                    'remaining_amount' => $plan?->remaining_amount,
                ]);
                return;
            }
        }

        // 1. Fetch all active (non-expired) subscriptions for the user
        $subscriptions = PushSubscription::where('user_id', $this->userId)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();

        // 2. If no subscriptions exist, store as database notification fallback
        if ($subscriptions->isEmpty()) {
            $this->storeAsDatabaseNotification();
            return;
        }

        // 3. Create WebPush instance with VAPID credentials
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid_subject'),
                'publicKey' => config('webpush.vapid_public_key'),
                'privateKey' => config('webpush.vapid_private_key'),
            ],
        ]);

        // 4. Queue notifications to each subscription
        foreach ($subscriptions as $pushSubscription) {
            $subscription = Subscription::create([
                'endpoint' => $pushSubscription->endpoint,
                'publicKey' => $pushSubscription->p256dh_key,
                'authToken' => $pushSubscription->auth_secret,
            ]);

            $webPush->queueNotification($subscription, json_encode($this->payload));
        }

        // 5. Flush and process results
        $allFailed = true;

        /** @var MessageSentReport $report */
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();

            if ($report->isSuccess()) {
                $allFailed = false;
            } elseif ($report->getResponse() && in_array($report->getResponse()->getStatusCode(), [404, 410])) {
                // 6. On 404/410: subscription is expired/invalid, remove from database
                PushSubscription::where('user_id', $this->userId)
                    ->where('endpoint', $endpoint)
                    ->delete();

                Log::info('Push subscription removed (expired/invalid)', [
                    'user_id' => $this->userId,
                    'endpoint' => $endpoint,
                    'status_code' => $report->getResponse()->getStatusCode(),
                ]);
            } else {
                // 7. On network/server error: log failure, continue to next
                Log::warning('Push notification delivery failed', [
                    'user_id' => $this->userId,
                    'type' => $this->type,
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                ]);
            }
        }

        // 8. If ALL subscriptions failed, store as database notification fallback
        if ($allFailed) {
            $this->storeAsDatabaseNotification();
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendPushNotificationJob failed permanently', [
            'user_id' => $this->userId,
            'type' => $this->type,
            'exception' => $exception->getMessage(),
        ]);

        $this->storeAsDatabaseNotification();
    }

    /**
     * Store the notification as an unread database notification fallback.
     */
    private function storeAsDatabaseNotification(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            Log::warning('Cannot store fallback notification: user not found', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        $user->notify(new PushNotificationFallback($this->type, $this->payload));

        Log::info('Push notification stored as database fallback', [
            'user_id' => $this->userId,
            'type' => $this->type,
        ]);
    }
}
