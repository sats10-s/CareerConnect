<?php

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/announcements (see
 * Api\Admin\AnnouncementController::store). Authorization
 * (admin-only) is enforced by Gate::authorize('create', Announcement::class)
 * in the controller, not here - see App\Policies\AnnouncementPolicy
 * and this codebase's universal FormRequest convention of
 * authorize(): true (see e.g. WithdrawApplicationRequest's docblock).
 * A new announcement always starts DRAFT (App\Services\AnnouncementService::createDraft())
 * - `status` is never an accepted input field here.
 */
class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'category' => ['required', Rule::enum(AnnouncementCategory::class)],
            'audience' => ['required', Rule::enum(AnnouncementAudience::class)],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', function ($attribute, $value, $fail) {
                $publishAt = $this->input('publish_at');
                $reference = $publishAt ? Carbon::parse($publishAt) : Carbon::now();

                if (Carbon::parse($value)->lessThanOrEqualTo($reference)) {
                    $fail('The expires at must be a date after the publish date (or after now, if no publish date is given).');
                }
            }],
        ];
    }
}
