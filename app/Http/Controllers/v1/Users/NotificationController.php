<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Hide legacy “new order” payout alerts for orders that were never fully funded.
     */
    protected function vendorNotificationIsRelevant($notification): bool
    {
        $data = is_array($notification->data)
            ? $notification->data
            : (json_decode($notification->data ?? '[]', true) ?: []);

        if (($data['type'] ?? '') !== 'payment') {
            return true;
        }

        $orderId = $data['order_id'] ?? null;
        if (!$orderId) {
            return true;
        }

        $order = Order::find($orderId);

        return $order && $order->isPaidForFulfillment();
    }

    public function vendorNotifications(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $notifications = $user->notifications()
            ->whereNull('read_at') // Only get unread notifications
            ->latest()
            ->limit(50)
            ->get()
            ->filter(fn ($n) => $this->vendorNotificationIsRelevant($n))
            ->map(function ($notification) {
                $data = is_array($notification->data)
                    ? $notification->data
                    : (json_decode($notification->data, true) ?: []);

                $orderId = $data['order_id'] ?? null;
                $title = $data['title'] ?? 'New Notification';
                $message = $data['message'] ?? ($orderId
                    ? "Order #{$orderId} has an update."
                    : 'You have a new notification.');

                return [
                    'id' => $notification->id,
                    'type' => $data['type'] ?? 'info',
                    'title' => $title,
                    'message' => $message,
                    'timestamp' => $notification->created_at,
                    'read' => !is_null($notification->read_at),
                    'data' => $data,
                ];
            })
            ->values();

        return response()->json([
            'notifications' => $notifications,
        ]);
    }

    public function allNotifications(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 20);

        $notifications = $user->notifications()
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        $formattedNotifications = collect($notifications->items())
            ->filter(fn ($n) => $this->vendorNotificationIsRelevant($n))
            ->map(function ($notification) {
                $data = is_array($notification->data)
                    ? $notification->data
                    : (json_decode($notification->data, true) ?: []);

                $orderId = $data['order_id'] ?? null;
                $title = $data['title'] ?? 'New Notification';
                $message = $data['message'] ?? ($orderId
                    ? "Order #{$orderId} has an update."
                    : 'You have a new notification.');

                return [
                    'id' => $notification->id,
                    'type' => $data['type'] ?? 'info',
                    'title' => $title,
                    'message' => $message,
                    'timestamp' => $notification->created_at,
                    'read' => !is_null($notification->read_at),
                    'data' => $data,
                ];
            })
            ->values();

        return response()->json([
            'notifications' => $formattedNotifications,
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    public function markOneAsRead(Request $request, string $id)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $notification = $user->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        if (!$this->vendorNotificationIsRelevant($notification)) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'id' => $notification->id,
        ]);
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $count = $user->unreadNotifications()->count();
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'message' => 'Notifications marked as read.',
            'updated_count' => $count,
        ]);
    }
}

