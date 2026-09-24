<?php

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-parameter validation for every announcement index
 * endpoint - GET /api/student/announcements, GET
 * /api/faculty/announcements, GET /api/admin/announcements - one class
 * rather than three nearly identical ones, mirroring
 * WeeklyProgressReportFilterRequest exactly. `status`/`audience` are
 * only ever read by Api\Admin\AnnouncementController::index() - the
 * student/faculty controllers always call
 * App\Services\AnnouncementService::activeFor(), which unconditionally
 * scopes to PUBLISHED-and-visible-to-the-caller's-own-role regardless
 * of what this request validates, so a student can never widen their
 * own feed to see drafts or another role's audience just by supplying
 * these fields (see each *AnnouncementController's docblock).
 */
class AnnouncementFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::enum(AnnouncementCategory::class)],
            'audience' => ['nullable', Rule::enum(AnnouncementAudience::class)],
            'status' => ['nullable', Rule::enum(AnnouncementStatus::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
