<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-parameter validation for every notification index
 * endpoint - GET /api/student/notifications, GET
 * /api/faculty/notifications, GET /api/admin/notifications - one class
 * rather than three identical ones, mirroring
 * WeeklyProgressReportFilterRequest/AttendanceFilterRequest exactly.
 * There is no student_id/faculty_id/recipient_user_id filter here at
 * all - unlike every other *FilterRequest in this codebase, a
 * notification index is NEVER filterable by whose notifications to
 * show (see App\Services\NotificationService::paginateForUser(), which
 * takes the recipient User directly, never from request input) -
 * accepting such a parameter here would be the exact IDOR the module
 * spec warns against, not a convenience.
 */
class NotificationFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['read', 'unread'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
