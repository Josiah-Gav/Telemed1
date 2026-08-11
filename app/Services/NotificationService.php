<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Centralized notification creation service.
 *
 * Controllers should call NotificationService::send() or
 * NotificationService::sendUnique() instead of creating Notification
 * records directly.
 */
class NotificationService
{
    /**
     * Create a notification for a single user.
     *
     * @param int|User $userId Recipient user id or User model.
     * @param NotificationType|string $type Notification type.
     * @param string $title Notification title.
     * @param string $message Notification message body.
     * @param array $data Optional contextual data (consultation_id, etc.).
     * @return Notification|null
     */
    public static function send(int|User $userId, NotificationType|string $type, string $title, string $message, array $data = []): ?Notification
    {
        $recipientId = $userId instanceof User ? $userId->user_id : $userId;
        $typeValue = $type instanceof NotificationType ? $type->value : $type;

        if (!NotificationType::isValid($typeValue)) {
            return null;
        }

        if (!$recipientId || $recipientId <= 0) {
            return null;
        }

        return Notification::create([
            'user_id' => $recipientId,
            'type' => $typeValue,
            'title' => $title,
            'message' => $message,
            'data' => !empty($data) ? $data : null,
        ]);
    }

    /**
     * Create a notification for every user with the given role.
     *
     * @param string $role 'patient', 'nurse', 'physician', or 'admin'
     * @param NotificationType|string $type Notification type.
     * @param string $title Notification title.
     * @param string $message Notification message body.
     * @param array $data Optional contextual data.
     * @return int Number of notifications created.
     */
    public static function sendToRole(string $role, NotificationType|string $type, string $title, string $message, array $data = []): int
    {
        $typeValue = $type instanceof NotificationType ? $type->value : $type;

        if (!NotificationType::isValid($typeValue)) {
            return 0;
        }

        $recipientIds = User::query()
            ->where('role', $role)
            ->where('account_status', 'active')
            ->pluck('user_id')
            ->all();

        if (empty($recipientIds)) {
            return 0;
        }

        $now = now();
        $rows = array_map(static fn (int $userId) => [
            'user_id' => $userId,
            'type' => $typeValue,
            'title' => $title,
            'message' => $message,
            'data' => !empty($data) ? json_encode($data) : null,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $recipientIds);

        DB::table('notifications')->insert($rows);

        return count($rows);
    }

    /**
     * Create a notification only if an identical notification for the same
     * event + entity does not already exist.
     *
     * The uniqueness check uses the notification type plus the keys inside
     * $data that are marked as entity identifiers (e.g. consultation_id,
     * follow_up_request_id, schedule_slot_id, session_id).
     *
     * Example:
     *   NotificationService::sendUnique(
     *       $patient->user_id,
     *       NotificationType::CONSULTATION_SCHEDULED,
     *       'Consultation Scheduled',
     *       'Your consultation is scheduled for 2:00 PM.',
     *       ['consultation_id' => 123, 'schedule_slot_id' => 55]
     *   );
     *
     * @param int|User $userId
     * @param NotificationType|string $type
     * @param string $title
     * @param string $message
     * @param array $data
     * @return Notification|null
     */
    public static function sendUnique(int|User $userId, NotificationType|string $type, string $title, string $message, array $data = []): ?Notification
    {
        $recipientId = $userId instanceof User ? $userId->user_id : $userId;
        $typeValue = $type instanceof NotificationType ? $type->value : $type;

        if (!NotificationType::isValid($typeValue)) {
            return null;
        }

        if (!$recipientId || $recipientId <= 0) {
            return null;
        }

        if (self::alreadyNotified($recipientId, $typeValue, $data)) {
            return null;
        }

        return Notification::create([
            'user_id' => $recipientId,
            'type' => $typeValue,
            'title' => $title,
            'message' => $message,
            'data' => !empty($data) ? $data : null,
        ]);
    }

    /**
     * Determine whether the user has already received a notification of the
     * given type for the same entity.
     *
     * @param int $userId
     * @param string $typeValue
     * @param array $data
     * @return bool
     */
    private static function alreadyNotified(int $userId, string $typeValue, array $data): bool
    {
        $entityKeys = array_intersect(
            array_keys($data),
            [
                'consultation_id',
                'follow_up_request_id',
                'schedule_slot_id',
                'session_id',
                'request_id',
                'message_id',
            ]
        );

        if (empty($entityKeys)) {
            return false;
        }

        $query = Notification::query()
            ->forUser($userId)
            ->where('type', $typeValue);

        foreach ($entityKeys as $key) {
            $query->whereJsonContains('data->' . $key, $data[$key]);
        }

        return $query->exists();
    }
}