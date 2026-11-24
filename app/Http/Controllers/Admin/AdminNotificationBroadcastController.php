<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotificationAutomation;
use App\Services\AdminNotificationBroadcastService;
use Illuminate\Http\Request;

class AdminNotificationBroadcastController extends Controller
{
    public function automations()
    {
        foreach (AdminNotificationBroadcastService::AUDIENCES as $audience) {
            AdminNotificationAutomation::firstOrCreate(['audience' => $audience], ['enabled' => false, 'frequency' => 'daily']);
        }

        return response()->json(['data' => AdminNotificationAutomation::whereIn('audience', AdminNotificationBroadcastService::AUDIENCES)->orderBy('id')->get()]);
    }

    public function updateAutomation(Request $request, string $audience)
    {
        abort_unless(in_array($audience, AdminNotificationBroadcastService::AUDIENCES, true), 404);
        $data = $request->validate([
            'title' => 'nullable|string|max:120',
            'message' => 'nullable|string|max:2000',
            'enabled' => 'required|boolean',
            'frequency' => 'required|in:hourly,daily,weekly,monthly',
        ]);
        if ($data['enabled'] && (empty($data['title']) || empty($data['message']))) {
            return response()->json(['message' => 'Enter a title and message before enabling automatic notifications.'], 422);
        }

        $automation = AdminNotificationAutomation::firstOrNew(['audience' => $audience]);
        $automation->fill($data);
        $automation->next_run_at = $data['enabled'] ? match ($data['frequency']) {
            'hourly' => now()->addHour(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => now()->addDay(),
        } : null;
        $automation->save();

        return response()->json(['data' => $automation]);
    }

    public function send(Request $request, AdminNotificationBroadcastService $broadcasts)
    {
        $data = $request->validate([
            'audience' => 'required|in:customer,vendor,agent',
            'title' => 'required|string|max:120',
            'message' => 'required|string|max:2000',
        ]);
        $count = $broadcasts->send($data['audience'], $data['title'], $data['message']);

        return response()->json(['message' => 'Notification queued.', 'queued_count' => $count]);
    }
}
