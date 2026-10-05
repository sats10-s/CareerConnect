<?php

namespace App\Services\Student;

use App\Enums\AcademicRecordSource;
use App\Enums\NotificationType;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a student's CGPA/backlogs after they are first
 * imported. Every change - from a results-update file or a single
 * "Update academics" edit in the admin Students drawer - goes through
 * apply(): the student row is updated, a student_academic_records row keeps
 * the old and new values with who/when/why, and the student is notified.
 *
 * Eligibility reads students.cgpa/backlogs directly, so a change applies to
 * every eligibility check immediately. Existing applications are left alone;
 * the admin Applications list flags the ones that no longer meet criteria.
 */
class AcademicRecordService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Records the first values a student is imported with (no "previous").
     */
    public function recordInitial(Student $student, ?int $importId, ?User $actor, ?string $semesterLabel = null): ?StudentAcademicRecord
    {
        if ($student->cgpa === null && $student->backlogs === null) {
            return null;
        }

        return StudentAcademicRecord::create([
            'student_id' => $student->id,
            'cgpa' => $student->cgpa,
            'backlogs' => $student->backlogs,
            'semester_label' => $semesterLabel,
            'source' => AcademicRecordSource::IMPORT,
            'import_id' => $importId,
            'changed_by' => $actor?->id,
            'reason' => 'Initial college record',
        ]);
    }

    /**
     * Applies new CGPA/backlogs. A null argument means "not in this update"
     * and keeps the current value - a blank never erases. Returns the
     * history row, or null when nothing actually changed.
     */
    public function apply(
        Student $student,
        ?float $cgpa,
        ?int $backlogs,
        AcademicRecordSource $source,
        ?User $actor,
        ?string $reason = null,
        ?string $semesterLabel = null,
        ?int $importId = null,
        bool $notify = true,
    ): ?StudentAcademicRecord {
        $previousCgpa = $student->cgpa === null ? null : (float) $student->cgpa;
        $previousBacklogs = $student->backlogs;

        $newCgpa = $cgpa ?? $previousCgpa;
        $newBacklogs = $backlogs ?? $previousBacklogs;

        $cgpaChanged = $newCgpa !== null && ($previousCgpa === null || abs($newCgpa - $previousCgpa) >= 0.005);
        $backlogsChanged = $newBacklogs !== null && $newBacklogs !== $previousBacklogs;

        if (! $cgpaChanged && ! $backlogsChanged) {
            return null;
        }

        $record = DB::transaction(function () use ($student, $newCgpa, $newBacklogs, $previousCgpa, $previousBacklogs, $source, $actor, $reason, $semesterLabel, $importId) {
            $student->forceFill([
                'cgpa' => $newCgpa,
                'backlogs' => $newBacklogs,
                'academics_updated_at' => now(),
            ])->save();

            return StudentAcademicRecord::create([
                'student_id' => $student->id,
                'cgpa' => $newCgpa,
                'backlogs' => $newBacklogs,
                'previous_cgpa' => $previousCgpa,
                'previous_backlogs' => $previousBacklogs,
                'semester_label' => $semesterLabel,
                'source' => $source,
                'import_id' => $importId,
                'changed_by' => $actor?->id,
                'reason' => $reason,
            ]);
        });

        if ($source === AcademicRecordSource::MANUAL) {
            $this->audit->record(
                'student.academics_updated',
                "Updated academics for {$student->roll_number}",
                $student,
                ['cgpa' => ['from' => $previousCgpa, 'to' => $newCgpa], 'backlogs' => ['from' => $previousBacklogs, 'to' => $newBacklogs]],
                $actor,
            );
        }

        if ($notify && $student->user) {
            $this->notifications->create(
                $student->user,
                NotificationType::ACADEMIC_RECORD_UPDATED,
                'Academic record updated',
                self::notificationMessage($newCgpa, $newBacklogs),
                ['student_id' => $student->id],
            );
        }

        return $record;
    }

    public static function notificationMessage(?float $cgpa, ?int $backlogs): string
    {
        $cgpaText = $cgpa === null ? 'not available' : number_format($cgpa, 2);
        $backlogText = $backlogs === null ? 'not reported' : (string) $backlogs;

        return "Your academic record was updated: CGPA {$cgpaText}, backlogs {$backlogText}.";
    }
}
