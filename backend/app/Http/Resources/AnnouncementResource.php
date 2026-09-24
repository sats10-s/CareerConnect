<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single representation used for every announcement endpoint
 * (list and single) across all three role namespaces - matches the
 * module spec's response shape (section 10). `status` is always
 * included: an admin is allowed to see it regardless of value, and a
 * student/faculty member can only ever reach an announcement whose
 * status is PUBLISHED in the first place (see
 * App\Policies\AnnouncementPolicy::view() /
 * App\Services\AnnouncementService::activeFor()), so surfacing it here
 * never leaks a draft/archived announcement's status to a viewer who
 * couldn't already see the announcement itself. `created_by`/`updated_by`
 * are only populated when the controller eager-loaded those relations
 * (every controller in this module does - see each *AnnouncementController).
 *
 * @mixin \App\Models\Announcement
 */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category->value,
            'audience' => $this->audience->value,
            'status' => $this->status->value,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
