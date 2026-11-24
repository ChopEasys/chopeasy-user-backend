<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Models\User;

class AdminNotificationBroadcastService
{
    public const AUDIENCES = ['customer', 'vendor', 'agent'];

    public function send(string $audience, string $title, string $message): int
    {
        abort_unless(in_array($audience, self::AUDIENCES, true), 422, 'Unsupported notification audience.');

        $count = 0;
        User::query()->where('user_type', $audience)->where('is_active', true)
            ->select('id')->orderBy('id')->chunkById(500, function ($users) use ($audience, $title, $message, &$count) {
                foreach ($users as $user) {
                    SendPushNotificationJob::dispatch($user->id, 'admin_broadcast', [
                        'title' => $title,
                        'body' => $message,
                        'message' => $message,
                        'type' => 'admin_broadcast',
                        'audience' => $audience,
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
