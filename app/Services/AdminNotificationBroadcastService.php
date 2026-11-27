<?php

namespace App\Services;

use App\Jobs\SendPushNotificationJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AdminNotificationBroadcastService
{
    public const AUDIENCES = ['customer', 'vendor', 'agent'];

    public function send(string $audience, string $title, string $message): int
    {
        abort_unless(in_array($audience, self::AUDIENCES, true), 422, 'Unsupported notification audience.');

        return $this->sendToUsers(User::query()->where('user_type', $audience)->where('is_active', true), $audience, $title, $message);
    }

    public function sendToUsers(Builder $users, string $audience, string $title, string $message, string $type = 'admin_broadcast'): int
    {
        $count = 0;
        $users
            ->select('id')->orderBy('id')->chunkById(500, function ($users) use ($audience, $title, $message, $type, &$count) {
                foreach ($users as $user) {
                    SendPushNotificationJob::dispatch($user->id, $type, [
                        'title' => $title,
                        'body' => $message,
                        'message' => $message,
                        'type' => $type,
                        'audience' => $audience,
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
