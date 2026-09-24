<?php

namespace App\Services;

use App\Enums\AnnouncementStatus;
use App\Enums\NotificationType;
use App\Exceptions\AnnouncementException;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single place every announcement-lifecycle write goes through:
 * creating a draft, editing a draft, publishing, and archiving. Each
 * mutating method is atomic (its own DB::transaction()) - callers
 * (Api\Admin\AnnouncementController) never touch `announcements`
 * directly. publish() is also the ONLY place the notification fan-out
 * (module spec section 8) is triggered - never from index()/show(),
 * and guarded by `notified_at` so it can never run twice for the same
 * announcement (see notifyAudience()'s own docblock).
 */
class AnnouncementService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function createDraft(array $data, User $actingUser): Announcement
    {
        return DB::transaction(fn () => Announcement::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'],
            'audience' => $data['audience'],
            'status' => AnnouncementStatus::DRAFT,
            'publish_at' => $data['publish_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $actingUser->id,
        ]));
    }

    /**
     * Only a DRAFT announcement may be edited - see
     * App\Policies\AnnouncementPolicy::update(), which enforces the
     * same rule before this method is ever reached; re-checked here
     * too since the service, not the policy, is this table's actual
     * source of truth for what a write may do.
     *
     * @param array<string, mixed> $data
     */
    public function updateDraft(Announcement $announcement, array $data, User $actingUser): Announcement
    {
        if ($announcement->status !== AnnouncementStatus::DRAFT) {
            throw new AnnouncementException('Only a draft announcement can be edited.');
        }

        return DB::transaction(function () use ($announcement, $data, $actingUser) {
            $updates = array_intersect_key($data, array_flip(['title', 'body', 'category', 'audience', 'publish_at', 'expires_at']));
            $updates['updated_by'] = $actingUser->id;

            $announcement->update($updates);

            return $announcement->fresh();
        });
    }

    /**
     * DRAFT -> PUBLISHED, defaulting publish_at to now() if the draft
     * never had one set - "published" without an explicit future
     * publish_at means "visible immediately", not "visible never".
     * Triggers the one-time notification fan-out (notifyAudience())
     * inside the same transaction as the status write.
     */
    public function publish(Announcement $announcement, User $actingUser): Announcement
    {
        if (! $announcement->status->canTransitionTo(AnnouncementStatus::PUBLISHED)) {
            throw new AnnouncementException(sprintf(
                'This announcement cannot move from %s to PUBLISHED.',
                $announcement->status->value,
            ));
        }

        return DB::transaction(function () use ($announcement, $actingUser) {
            $announcement->update([
                'status' => AnnouncementStatus::PUBLISHED,
                'publish_at' => $announcement->publish_at ?? now(),
                'updated_by' => $actingUser->id,
            ]);

            $this->notifyAudience($announcement->fresh());

            return $announcement->fresh();
        });
    }

    /**
     * PUBLISHED -> ARCHIVED only - see App\Enums\AnnouncementStatus's
     * own docblock for why DRAFT -> ARCHIVED and ARCHIVED -> PUBLISHED
     * are both deliberately impossible.
     */
    public function archive(Announcement $announcement, User $actingUser): Announcement
    {
        if (! $announcement->status->canTransitionTo(AnnouncementStatus::ARCHIVED)) {
            throw new AnnouncementException(sprintf(
                'This announcement cannot move from %s to ARCHIVED.',
                $announcement->status->value,
            ));
        }

        return DB::transaction(function () use ($announcement, $actingUser) {
            $announcement->update([
                'status' => AnnouncementStatus::ARCHIVED,
                'updated_by' => $actingUser->id,
            ]);

            return $announcement->fresh();
        });
    }

    /**
     * Active (PUBLISHED, publish_at reached, not expired) announcements
     * visible to $user's role, newest-published-first. Used by
     * Api\Student\AnnouncementController / Api\Faculty\AnnouncementController -
     * an admin's own richer, unfiltered-by-audience index() stays in
     * Api\Admin\AnnouncementController, built directly off the model,
     * since an admin is allowed to see drafts/archived/every-audience
     * announcement (see App\Policies\AnnouncementPolicy::viewAny()).
     *
     * @param array<string, mixed> $filters
     */
    public function activeFor(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Announcement::query()->active()->visibleTo($user)->with('creator');

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%");
            });
        }

        return $query->latest('publish_at')->paginate($perPage);
    }

    /**
     * Fans the announcement out to every active user in its audience,
     * exactly once. Guarded by `notified_at` (see the announcements
     * migration's docblock) rather than, say, a unique index on
     * (announcement_id) in `notifications.data` - the simplest possible
     * idempotency guard for "this specific announcement's fan-out has
     * already run", immune to being re-triggered by a repeated GET
     * fetch (module spec section 8's explicit requirement) since
     * nothing outside this method ever calls it.
     */
    private function notifyAudience(Announcement $announcement): void
    {
        if ($announcement->notified_at !== null) {
            return;
        }

        $recipientIds = User::query()
            ->whereHas('role', fn (Builder $q) => $q->whereIn('name', $announcement->audience->roleNames()))
            ->where('is_active', true)
            ->select('id')
            ->pluck('id');

        $this->notificationService->createMany(
            $recipientIds,
            NotificationType::ANNOUNCEMENT,
            $announcement->title,
            Str::limit($announcement->body, 500),
            ['announcement_id' => $announcement->id, 'category' => $announcement->category->value],
        );

        $announcement->update(['notified_at' => now()]);
    }
}
