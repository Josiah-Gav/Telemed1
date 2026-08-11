<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Return the authenticated user's notifications, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->forUser((int) $request->user()->user_id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => collect($notifications->items())->map(fn (Notification $notification) => $this->serialize($notification)),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Return the authenticated user's unread notification count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $unreadCount = Notification::query()
            ->forUser((int) $request->user()->user_id)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Mark a single notification as read.
     *
     * Users may only mark their own notifications as read.
     */
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        if ((int) $notification->user_id !== (int) $request->user()->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($notification->read_at === null) {
            $notification->update([
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->serialize($notification->fresh()),
        ]);
    }

    /**
     * Mark all of the authenticated user's notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = Notification::query()
            ->forUser((int) $request->user()->user_id)
            ->unread()
            ->update([
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'updated_count' => $updated,
            ],
        ]);
    }

    private function serialize(Notification $notification): array
    {
        return [
            'notification_id' => $notification->notification_id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'data' => $notification->data,
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
        ];
    }
}