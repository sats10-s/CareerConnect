<?php

namespace App\Services\Student;

use App\Enums\CorrectionRequestStatus;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Models\CorrectionRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;

/**
 * "Report a correction" on a locked college-record field: the student can't
 * change it, so this records what they think is wrong and tells the
 * placement office (every active Placement Admin and Super Admin).
 */
class CorrectionRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Student $student, string $field, string $note): CorrectionRequest
    {
        $request = CorrectionRequest::create([
            'student_id' => $student->id,
            'field' => $field,
            'note' => $note,
            'status' => CorrectionRequestStatus::OPEN,
        ]);

        $label = CorrectionRequest::FIELDS[$field] ?? $field;
        $recipients = User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', [RoleName::PLACEMENT_ADMIN->value, RoleName::SUPER_ADMIN->value]))
            ->pluck('id');

        $this->notifications->createMany(
            $recipients,
            NotificationType::CORRECTION_REQUESTED,
            'Correction requested',
            "{$student->user?->name} ({$student->roll_number}) says their {$label} is wrong: ".mb_strimwidth($note, 0, 200, '…'),
            ['student_id' => $student->id, 'correction_request_id' => $request->id],
        );

        $this->audit->record('student.correction_requested', "{$student->roll_number} reported a correction to {$label}", $request, ['field' => $field], $student->user);

        return $request;
    }

    public function resolve(CorrectionRequest $request, User $actor): CorrectionRequest
    {
        if ($request->status !== CorrectionRequestStatus::RESOLVED) {
            $request->update(['status' => CorrectionRequestStatus::RESOLVED, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
        }

        return $request->fresh('resolvedBy');
    }
}
