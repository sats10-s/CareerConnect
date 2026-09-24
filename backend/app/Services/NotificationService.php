<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The single place every notification write goes through - creating
 * one notification, bulk-creating many (an announcement's audience
 * fan-out), marking one read, and marking all of a user's own
 * notifications read. Callers (App\Listeners\*,
 * App\Services\AnnouncementService, every Api\*\NotificationController)
 * never touch the `notifications` table directly.
 *
 * Every method that targets a recipient takes the User (or an iterable
 * of them) explicitly - this service never re-derives "who" from
 * auth()/the request, mirroring every other service in this codebase
 * (see App\Services\ApplicationService's own docblock for the same
 * rule). Read/pagination methods are always scoped to the given User's
 * own id - there is no "list any user's notifications" method here at
 * all, which is what makes IDOR impossible to introduce by accident
 * from this layer (see App\Policies\NotificationPolicy for the
 * matching object-level guard on the single-notification actions).
 */
class NotificationService
{
    /**
     * Bulk-insert chunk size for createMany() - keeps one very large
     * audience (module spec section 8: "for large audiences, use
     * efficient bulk insertion") from building a single multi-thousand-
     * row INSERT statement, without holding the whole recipient list in
     * memory at once (see createMany()'s own docblock on the iterable
     * parameter).
     */
    private const BULK_INSERT_CHUNK = 500;

    public function create(User $recipient, NotificationType $type, string $title, string $message, array $data = []): Notification
    {
        return Notification::create([
            'recipient_user_id' => $recipient->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Bulk-creates the SAME notification for many recipients in one or
     * more chunked INSERT statements - never one Eloquent ->create()
     * (and therefore one INSERT + one model-hydration) per recipient.
     * `$recipients` is deliberately typed `iterable` rather than
     * `Collection`/`array` so a caller can pass a lazy, memory-bounded
     * source (App\Services\AnnouncementService::recipientsFor() passes
     * a query ->cursor()) for an audience of any size without loading
     * every recipient into memory up front.
     *
     * Each element of `$recipients` may be a User instance or a plain
     * integer/numeric id - AnnouncementService passes bare ids (it only
     * ever needs the id column for this, so its own query selects just
     * that column) rather than fully-hydrated User models.
     *
     * Structured so this call can be wrapped in a queued Job later
     * (module spec section 8: "structure so notification fan-out can be
     * queued later without redesigning the domain model") with zero
     * change to this method or its callers - it already takes only
     * plain, serializable data (an enum case, two strings, an array,
     * and a list of ids) and performs no side effect beyond the
     * inserts themselves, exactly what a Job's handle() would need.
     *
     * @param iterable<User|int> $recipients
     */
    public function createMany(iterable $recipients, NotificationType $type, string $title, string $message, array $data = []): int
    {
        $now = now();
        $encodedData = $data === [] ? null : json_encode($data);
        $buffer = [];
        $count = 0;

        foreach ($recipients as $recipient) {
            $recipientId = $recipient instanceof User ? $recipient->id : (int) $recipient;

            $buffer[] = [
                'recipient_user_id' => $recipientId,
                'type' => $type->value,
                'title' => $title,
                'message' => $message,
                'data' => $encodedData,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::BULK_INSERT_CHUNK) {
                Notification::insert($buffer);
                $count += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            Notification::insert($buffer);
            $count += count($buffer);
        }

        return $count;
    }

    /**
     * Idempotent - marking an already-read notification read again is
     * simply a no-op (no error, no duplicate history), since "read" has
     * no further state beyond the timestamp itself.
     */
    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }

    /**
     * Marks every currently-unread notification belonging to $user as
     * read in a single UPDATE statement. Returns the number of rows
     * actually updated.
     */
    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Newest-first, always scoped to $user's own notifications.
     * $filters['status'] may be 'read'/'unread' (see
     * App\Http\Requests\Notification\NotificationFilterRequest) -
     * anything else is ignored rather than rejected, matching this
     * codebase's existing filter-request convention.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateForUser(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Notification::query()->where('recipient_user_id', $user->id);

        if (($filters['status'] ?? null) === 'unread') {
            $query->whereNull('read_at');
        } elseif (($filters['status'] ?? null) === 'read') {
            $query->whereNotNull('read_at');
        }

        return $query->latest('created_at')->paginate($perPage);
    }
}
