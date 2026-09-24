<?php

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/admin/announcements/{announcement} (see
 * Api\Admin\AnnouncementController::update). Every field is
 * `sometimes` - a partial edit only touches the fields it sends.
 * Authorization (admin-only, DRAFT-only - see
 * App\Services\AnnouncementService::updateDraft()) is enforced in the
 * controller/service, not here. `expires_at`'s comparison falls back
 * to the announcement's CURRENT publish_at when this request doesn't
 * itself send one, so editing just the body/title of a
 * publish_at-scheduled draft can never silently invalidate its
 * already-set expiry.
 */
class UpdateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'category' => ['sometimes', Rule::enum(AnnouncementCategory::class)],
            'audience' => ['sometimes', Rule::enum(AnnouncementAudience::class)],
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', function ($attribute, $value, $fail) {
                if ($value === null) {
                    return;
                }

                $announcement = $this->route('announcement');
                $publishAt = $this->has('publish_at') ? $this->input('publish_at') : $announcement?->publish_at;
                $reference = $publishAt ? Carbon::parse($publishAt) : Carbon::now();

                if (Carbon::parse($value)->lessThanOrEqualTo($reference)) {
                    $fail('The expires at must be a date after the publish date (or after now, if no publish date is given).');
                }
            }],
        ];
    }
}
