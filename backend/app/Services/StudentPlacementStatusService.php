<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentPlacementStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudentPlacementStatusService
{
    /** @param array{placement_company?: ?string, placement_job_role?: ?string, placement_package?: ?float, placement_date?: ?string} $details */
    public function update(Student $student, string $status, User $actor, array $details = [], ?string $reason = null): Student
    {
        return DB::transaction(function () use ($student, $status, $actor, $details, $reason) {
            $student->refresh();
            $previousStatus = $student->placement_status;
            $fields = ['placement_company', 'placement_job_role', 'placement_package', 'placement_date'];
            $oldDetails = collect($fields)->mapWithKeys(fn ($field) => [$field => $student->getAttribute($field)])->all();
            $updates = array_intersect_key($details, array_flip($fields));

            if ($status === 'NOT_PLACED') {
                $updates = array_fill_keys($fields, null);
            }

            $student->fill($updates + [
                'placement_status' => $status,
                'placement_status_updated_by' => $actor->id,
                'placement_status_updated_at' => now(),
            ])->save();

            StudentPlacementStatusHistory::create([
                'student_id' => $student->id,
                'from_status' => $previousStatus,
                'to_status' => $status,
                'old_details' => $oldDetails,
                'new_details' => collect($fields)->mapWithKeys(fn ($field) => [$field => $student->getAttribute($field)])->all(),
                'reason' => $reason,
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            return $student->refresh();
        });
    }
}
