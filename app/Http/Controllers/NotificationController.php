<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * The same today/last_7_days/last_30_days/all vocabulary
     * ConsultationHistoryQuery::ALLOWED_DATE_FILTERS uses, so a user who has
     * already used that filter on consultation history recognizes it here.
     * Kept as its own copy rather than reusing that class: it is documented
     * as the source of truth for consultation-history filtering
     * specifically, and notifications have no reason to change in lockstep
     * with it.
     */
    private const ALLOWED_DATE_FILTERS = ['today', 'last_7_days', 'last_30_days', 'all'];

    /**
     * Return the authenticated user's notifications, newest first.
     *
     * ?unread=1 restricts to unread notifications only, and ?date_filter=
     * (today|last_7_days|last_30_days) restricts by created_at — both used
     * by the "All Notifications" page's tabs and date filter
     * (notifications/index.blade.php) so it can page through the full
     * matching set rather than filtering whatever page happened to load.
     */
    public function index(Request $request): JsonResponse
    {
        $dateFilter = $request->query('date_filter', 'all');
        $dateFilter = in_array($dateFilter, self::ALLOWED_DATE_FILTERS, true) ? $dateFilter : 'all';

        $notifications = Notification::query()
            ->forUser((int) $request->user()->user_id)
            ->when($request->boolean('unread'), fn ($query) => $query->unread())
            ->when($dateFilter !== 'all', fn ($query) => match ($dateFilter) {
                'today' => $query->whereDate('created_at', now()->toDateString()),
                'last_7_days' => $query->where('created_at', '>=', now()->subDays(7)->startOfDay()),
                'last_30_days' => $query->where('created_at', '>=', now()->subDays(30)->startOfDay()),
            })
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
     * The full "All Notifications" page (routes/web.php: notifications.all).
     * Server-renders the first page of results so there's no loading-spinner
     * flash on open; the page's Alpine component fetches subsequent pages
     * and tab switches from index() above, the same JSON endpoint the header
     * dropdown already uses.
     */
    public function all(Request $request)
    {
        return view('notifications.index', [
            'initialResponse' => $this->index($request)->getData(true),
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