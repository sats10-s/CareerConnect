<?php

namespace App\Http\Requests\Ppo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/faculty/internship-assignments/{assignment}/ppo - see
 * Api\Faculty\PpoRecordController::store(). There is deliberately no
 * `company_id` or `status` field here at all: company_id is always
 * DERIVED from the assignment's own internship
 * (App\Services\InternshipCompletionService::createPpo()) and status
 * is always OFFERED on creation - neither is ever accepted from the
 * client, closing the obvious "offer a PPO on behalf of a different
 * company" / "create a PPO that's already ACCEPTED" spoofing paths.
 *
 * NOTE ON ROUTING: the module spec's own route list (section 6) gives
 * Faculty no PPO write route at all - only GET /api/admin/ppo-records
 * (admin, read-only) and GET /api/student/ppo (student, read-only).
 * But section 4 explicitly requires "create/update PPO" as a service
 * action, and section 9's test list requires "PPO creation/update" -
 * so SOME client-reachable write path must exist. This route
 * (POST /api/faculty/internship-assignments/{assignment}/ppo) is a
 * reasoned addition, chosen by direct symmetry with the completion
 * route the spec DID give (POST/PATCH .../internship-assignments/{assignment}/completion) -
 * faculty already owns every other write action scoped to their own
 * assigned students in this module (and in Attendance/Weekly Progress
 * Reports/Internship Documents before it), so extending that same
 * "faculty writes, admin monitors, student views" shape to PPO is the
 * most consistent choice available, rather than leaving PPO with no
 * way to ever be created at all.
 */
class StorePpoRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'offered_at' => ['required', 'date', 'before_or_equal:today'],
            'joining_date' => ['nullable', 'date', 'after_or_equal:offered_at'],
            'designation' => ['required', 'string', 'max:255'],
            'package' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
