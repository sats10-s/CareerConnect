<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin-authored announcement - see the announcements migration's
 * docblock and App\Services\AnnouncementService (the only writer). All
 * lifecycle writes (draft creation/edit, publish, archive) go
 * exclusively through that service, which is also the only place
 * `notified_at` is ever set - nothing else should call
 * ->update(['status' => ...]) directly.
 */
class Announcement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'category',
        'audience',
        'status',
        'publish_at',
        'expires_at',
        'created_by',
        'updated_by',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => AnnouncementCategory::class,
            'audience' => AnnouncementAudience::class,
            'status' => AnnouncementStatus::class,
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * PUBLISHED, its publish_at (if any) already reached, and not yet
     * past its expires_at (if any) - the exact "appear to the
     * appropriate audience / respect publish_at / respect expires_at"
     * rule from the module spec's own section 7. Deliberately does NOT
     * filter by audience - see scopeVisibleTo() for that, kept as a
     * separate scope so App\Policies\AnnouncementPolicy::view() can
     * reuse just the time/status half of this rule for a single
     * announcement it already has in hand.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', AnnouncementStatus::PUBLISHED->value)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    /**
     * Announcements whose audience this user's role belongs to (ALL
     * always matches). See App\Enums\AnnouncementAudience::roleNames()
     * for what each audience value expands to - PLACEMENT_ADMIN there
     * covers both PLACEMENT_ADMIN and SUPER_ADMIN, mirroring the
     * role:PLACEMENT_ADMIN,SUPER_ADMIN pairing every admin-only route
     * in this app already uses.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $role = $user->roleName();
        $roleValue = $role?->value;

        return $query->where(function (Builder $outer) use ($roleValue) {
            $outer->where('audience', AnnouncementAudience::ALL->value);

            if ($roleValue === null) {
                return;
            }

            foreach (AnnouncementAudience::cases() as $audience) {
                if ($audience !== AnnouncementAudience::ALL && in_array($roleValue, $audience->roleNames(), true)) {
                    $outer->orWhere('audience', $audience->value);
                }
            }
        });
    }

    /**
     * Whether $user is allowed to see THIS announcement (already
     * PUBLISHED-and-active, and addressed to their role) - the same
     * rule as scopeActive()+scopeVisibleTo() combined, but evaluated
     * in PHP against a single already-loaded model rather than a
     * query, for App\Policies\AnnouncementPolicy::view()'s direct-by-id
     * IDOR guard (a student/faculty member must never be able to fetch
     * a DRAFT, ARCHIVED, not-yet-published, expired, or wrong-audience
     * announcement just by guessing its id).
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->status !== AnnouncementStatus::PUBLISHED) {
            return false;
        }

        $now = now();

        if ($this->publish_at !== null && $this->publish_at->isAfter($now)) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isBefore($now)) {
            return false;
        }

        $role = $user->roleName();

        if ($this->audience === AnnouncementAudience::ALL) {
            return true;
        }

        return $role !== null && in_array($role->value, $this->audience->roleNames(), true);
    }
}
