<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ExcelImportBatchStatus;
use App\Enums\ExcelImportType;
use App\Enums\ExcelRowOutcome;
use App\Enums\InternshipAssignmentStatus;
use App\Exceptions\ApplicationException;
use App\Exceptions\AttendanceException;
use App\Exceptions\ExcelImportException;
use App\Exceptions\InternshipAssignmentException;
use App\Exceptions\InterviewException;
use App\Exceptions\PpoRecordException;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\ExcelImportBatch;
use App\Models\ExcelImportChange;
use App\Models\ExcelImportRow;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\PpoRecord;
use App\Models\Student;
use App\Models\StudentPlacementStatusHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Excel Sync Center orchestrator - implements the module spec's
 * section 1/34 pipeline end to end: Excel -> Parse -> Normalize ->
 * Match -> Validate -> Preview -> (admin confirmation) -> Existing
 * Domain Service -> MySQL Transaction -> History -> Notifications ->
 * Audit. This is the ONLY class that ever writes an
 * ExcelImportBatch/ExcelImportRow/ExcelImportChange row, and the ONLY
 * class that ever calls the pre-existing domain services
 * (ApplicationService/InterviewService/InternshipAssignmentService/
 * InternshipCompletionService/AttendanceService) on behalf of an Excel
 * row - it NEVER writes to applications/interviews/internship_assignments/
 * ppo_records/attendance/students directly except for Student Master
 * import's plain scalar fields, which have no existing domain service
 * to delegate to (see confirmStudentMasterRow()'s own docblock).
 *
 * STORAGE SECURITY (module spec section 33): every uploaded file lives
 * on the `local` disk (storage/app/private/..., no public URL) under a
 * random 40-character filename with an extension derived from the
 * file's own detected type - never the client-supplied name - mirrors
 * App\Services\InternshipDocumentService::storeFile() exactly, this
 * codebase's established private-file-storage precedent.
 *
 * NOTIFICATIONS (module spec section 32): this codebase has no
 * persisted notification system at all yet (confirmed by grep - every
 * *StatusChanged event in app/Events has zero listeners; dispatching
 * the event IS the entire "existing lifecycle event/notification
 * mechanism"). Calling the existing domain services (which already
 * dispatch ApplicationStatusChanged/InterviewStatusChanged/etc.) is
 * therefore both necessary and sufficient to satisfy this requirement
 * - this class never dispatches a duplicate event and never builds a
 * second notification path.
 *
 * Most import types retain their established row-level partial-success
 * behavior. STUDENT_PLACEMENT_STATUS is the exception: every row is
 * revalidated first and the entire batch commits or rolls back together.
 */
class ExcelImportService
{
    private const DISK = 'local';

    private const MAX_FILE_SIZE_KB = 10240; // 10MB - see StoreExcelImportRequest for the enforced form-level limit.

    public function __construct(
        private readonly ExcelParsingService $parsingService,
        private readonly ExcelMatchingService $matchingService,
        private readonly ExcelValidationService $validationService,
        private readonly ApplicationService $applicationService,
        private readonly InterviewService $interviewService,
        private readonly InternshipAssignmentService $assignmentService,
        private readonly InternshipCompletionService $completionService,
        private readonly AttendanceService $attendanceService,
        private readonly StudentPlacementStatusService $placementStatusService,
    ) {}

    // =========================================================================
    // UPLOAD + PARSE + MATCH + VALIDATE
    // =========================================================================

    public function upload(UploadedFile $file, ExcelImportType $type, User $actingUser): ExcelImportBatch
    {
        $stored = $this->storeFile($file);

        $batch = ExcelImportBatch::create([
            'import_type' => $type,
            'uploaded_by' => $actingUser->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $stored['stored_filename'],
            'storage_path' => $stored['storage_path'],
            'status' => ExcelImportBatchStatus::UPLOADED,
        ]);

        try {
            $absolutePath = Storage::disk(self::DISK)->path($stored['storage_path']);
            $parsed = $this->parsingService->parse($absolutePath, $type);

            // A roll number is the authoritative identifier for this import.
            // Find repeats before building rows so every occurrence, including
            // the first, is clearly invalid in the preview.
            $placementDuplicateRows = $type === ExcelImportType::STUDENT_PLACEMENT_STATUS
                ? $this->duplicatePlacementStatusRows($parsed['rows'])
                : [];

            $totalRows = 0;
            $matchedRows = 0;
            $validRows = 0;
            $warningRows = 0;
            $errorRows = 0;

            foreach ($parsed['rows'] as $entry) {
                $totalRows++;
                $built = isset($placementDuplicateRows[$entry['row_number']])
                    ? $this->errorRow([$placementDuplicateRows[$entry['row_number']]])
                    : $this->buildRow($type, $entry['data']);

                if ($built['outcome'] !== ExcelRowOutcome::ERROR->value && $built['entity_id'] !== null) {
                    $matchedRows++;
                }

                match ($built['outcome']) {
                    ExcelRowOutcome::VALID->value => $validRows++,
                    ExcelRowOutcome::WARNING->value => $warningRows++,
                    ExcelRowOutcome::ERROR->value => $errorRows++,
                };

                ExcelImportRow::create([
                    'import_batch_id' => $batch->id,
                    'row_number' => $entry['row_number'],
                    'raw_row' => $entry['data'],
                    'entity_type' => $built['entity_type'],
                    'entity_id' => $built['entity_id'],
                    'confirm_context' => $built['confirm_context'],
                    'outcome' => $built['outcome'],
                    'messages' => $built['messages'],
                    'changes' => $built['changes'],
                ]);
            }

            $batch->update([
                'status' => ExcelImportBatchStatus::READY_FOR_CONFIRMATION,
                'total_rows' => $totalRows,
                'matched_rows' => $matchedRows,
                'valid_rows' => $validRows,
                'warning_rows' => $warningRows,
                'error_rows' => $errorRows,
            ]);

            return $batch->fresh();
        } catch (Throwable $e) {
            $batch->update([
                'status' => ExcelImportBatchStatus::FAILED,
                'error_message' => $e instanceof ExcelImportException ? $e->getMessage() : 'An unexpected error occurred while processing this file.',
            ]);

            Storage::disk(self::DISK)->delete($stored['storage_path']);

            throw $e instanceof ExcelImportException ? $e : new ExcelImportException('An unexpected error occurred while processing this file.', status: 500);
        }
    }

    /**
     * @param array<string, ?string> $data
     * @return array{outcome: string, entity_type: ?string, entity_id: ?int, confirm_context: ?array, messages: list<string>, changes: list<array>}
     */
    private function buildRow(ExcelImportType $type, array $data): array
    {
        return match (true) {
            $type === ExcelImportType::STUDENT_MASTER => $this->buildStudentMasterRow($data),
            $type === ExcelImportType::STUDENT_PLACEMENT_STATUS => $this->buildStudentPlacementStatusRow($data),
            $type->isApplicationStatusFamily() => $this->buildApplicationStatusRow($data),
            $type === ExcelImportType::INTERNSHIP_STATUS => $this->buildInternshipStatusRow($data),
            $type === ExcelImportType::INTERVIEW_SCHEDULE => $this->buildInterviewScheduleRow($data),
            $type === ExcelImportType::ATTENDANCE => $this->buildAttendanceRow($data),
        };
    }

    /** @param array<string, ?string> $data */
    private function buildStudentPlacementStatusRow(array $data): array
    {
        $match = $this->matchingService->matchStudent($data['roll_number'] ?? null, null);
        if ($match['outcome'] !== 'MATCHED') return $this->errorRow([$match['message']]);
        $student = $match['student'];
        $status = $this->validationService->normalizeStudentPlacementStatus($data['placement_status'] ?? null);
        if ($status === null) return $this->errorRow(['Placement Status must be PLACED or NOT_PLACED.']);

        $package = $data['package'] ?? null;
        if ($package !== null && (! is_numeric($package) || (float) $package < 0 || (float) $package > 99999999.99)) {
            return $this->errorRow(['Package must be a non-negative number no greater than 99999999.99.']);
        }
        $placementDate = $data['placement_date'] ?? null;
        if ($placementDate !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $placementDate) || ! checkdate((int) substr($placementDate, 5, 2), (int) substr($placementDate, 8, 2), (int) substr($placementDate, 0, 4)) || $placementDate > now()->toDateString())) {
            return $this->errorRow(['Placement Date must be a valid date that is not in the future.']);
        }

        $oldDetails = [
            'placement_company' => $student->placement_company,
            'placement_job_role' => $student->placement_job_role,
            'placement_package' => $student->placement_package === null ? null : (string) $student->placement_package,
            'placement_date' => $student->placement_date?->toDateString(),
        ];
        $newDetails = $status === 'NOT_PLACED' ? array_fill_keys(array_keys($oldDetails), null) : [
            'placement_company' => $data['company'] ?? $student->placement_company,
            'placement_job_role' => $data['job_role'] ?? $student->placement_job_role,
            'placement_package' => $package ?? $student->placement_package,
            'placement_date' => $placementDate ?? $student->placement_date?->toDateString(),
        ];
        if ($student->placement_status === $status && $oldDetails === $newDetails) {
            return $this->errorRow(['This student already has the requested placement status and details.']);
        }

        return [
            'outcome' => ExcelRowOutcome::VALID->value,
            'entity_type' => Student::class,
            'entity_id' => $student->id,
            'confirm_context' => [
                'from_status' => $student->placement_status,
                'target_status' => $status,
                'details' => $status === 'NOT_PLACED' ? [] : [
                    'placement_company' => $data['company'] ?? null,
                    'placement_job_role' => $data['job_role'] ?? null,
                    'placement_package' => $package === null ? null : (float) $package,
                    'placement_date' => $placementDate,
                ],
                'reason' => $data['reason'] ?? null,
                'old_details' => $oldDetails,
                'new_details' => $newDetails,
            ],
            'messages' => [],
            'changes' => [['field' => 'placement_status', 'label' => 'Placement Status', 'old_value' => (string) $student->placement_status, 'new_value' => $status]],
        ];
    }

    /**
     * @param list<array{row_number: int, data: array<string, ?string>}> $rows
     * @return array<int, string> row number => duplicate error message
     */
    private function duplicatePlacementStatusRows(array $rows): array
    {
        $byIdentifier = [];
        foreach ($rows as $row) {
            $identifier = $row['data']['roll_number'] ?? null;
            $normalized = mb_strtoupper(preg_replace('/\\s+/', ' ', trim((string) $identifier)) ?? '');
            if ($normalized !== '') $byIdentifier[$normalized][] = $row['row_number'];
        }

        $duplicates = [];
        foreach ($byIdentifier as $identifier => $rowNumbers) {
            if (count($rowNumbers) < 2) continue;
            $rowList = implode(', ', $rowNumbers);
            foreach ($rowNumbers as $rowNumber) {
                $duplicates[$rowNumber] = sprintf(
                    'Duplicate student identifier "%s" appears on rows %s; remove or correct every duplicate row before importing.',
                    $identifier,
                    $rowList,
                );
            }
        }

        return $duplicates;
    }

    // --- Placement/Application Status (+ Shortlist/Assessment/Final Selection) -------

    /**
     * @param array<string, ?string> $data
     */
    private function buildApplicationStatusRow(array $data): array
    {
        $messages = [];

        $match = $this->matchingService->matchStudent($data['roll_number'] ?? null, null);

        if ($match['outcome'] !== 'MATCHED') {
            return $this->errorRow([$match['message']]);
        }

        $student = $match['student'];

        if ($this->matchingService->nameLooksMismatched($student, $data['student_name'] ?? null)) {
            $messages[] = sprintf('The Excel name "%s" differs significantly from the matched student\'s name on record.', $data['student_name']);
        }

        $opportunityMatch = $this->matchingService->matchOpportunity($data['company'] ?? null, $data['job_role'] ?? null);

        if ($opportunityMatch['outcome'] !== 'MATCHED') {
            return $this->errorRow([...$messages, $opportunityMatch['message']]);
        }

        $opportunity = $opportunityMatch['opportunity'];
        $applicationMatch = $this->matchingService->matchApplication($student, $opportunity);

        if ($applicationMatch['outcome'] !== 'MATCHED') {
            return $this->errorRow([...$messages, $applicationMatch['message']]);
        }

        $application = $applicationMatch['application'];
        $target = $this->validationService->normalizeApplicationStatus($data['status'] ?? null);

        if ($target === null) {
            return $this->errorRow([...$messages, sprintf('Unknown status "%s".', $data['status'] ?? '')]);
        }

        $current = $application->status;

        if ($current === $target) {
            return $this->errorRow([...$messages, sprintf('This application is already %s.', $current->value)]);
        }

        if (! $current->canTransitionTo($target)) {
            return $this->errorRow([...$messages, sprintf('This application cannot move from %s to %s.', $current->value, $target->value)]);
        }

        return [
            'outcome' => $messages === [] ? ExcelRowOutcome::VALID->value : ExcelRowOutcome::WARNING->value,
            'entity_type' => Application::class,
            'entity_id' => $application->id,
            'confirm_context' => ['target_status' => $target->value, 'remarks' => $data['remarks'] ?? null],
            'messages' => $messages,
            'changes' => [['field' => 'status', 'label' => 'Application Status', 'old_value' => $current->value, 'new_value' => $target->value]],
        ];
    }

    private function confirmApplicationStatusRow(ExcelImportRow $row, ExcelImportBatch $batch, User $actingUser): void
    {
        $application = Application::find($row->entity_id);

        if ($application === null) {
            throw new ExcelImportException('The matched application no longer exists.');
        }

        $current = $application->status;
        $target = ApplicationStatus::from($row->confirm_context['target_status']);

        if (! $current->canTransitionTo($target)) {
            throw new ExcelImportException(sprintf('This application has since moved to %s and can no longer move to %s.', $current->value, $target->value));
        }

        $this->applicationService->updateStatus($application, $target, $actingUser, $row->confirm_context['remarks'] ?? null);

        $this->recordChange($batch, $row, Application::class, $application->id, 'status', $current->value, $target->value, $actingUser);
    }

    // --- Internship Status (assignment status + PPO status) -------------------------

    /**
     * @param array<string, ?string> $data
     */
    private function buildInternshipStatusRow(array $data): array
    {
        $messages = [];

        $match = $this->matchingService->matchStudent($data['roll_number'] ?? null, null);

        if ($match['outcome'] !== 'MATCHED') {
            return $this->errorRow([$match['message']]);
        }

        $student = $match['student'];

        if ($this->matchingService->nameLooksMismatched($student, $data['student_name'] ?? null)) {
            $messages[] = 'The Excel name differs significantly from the matched student\'s name on record.';
        }

        $opportunityMatch = $this->matchingService->matchOpportunity($data['company'] ?? null, $data['internship_role'] ?? null, Internship::class);

        if ($opportunityMatch['outcome'] !== 'MATCHED') {
            return $this->errorRow([...$messages, $opportunityMatch['message']]);
        }

        /** @var Internship $internship */
        $internship = $opportunityMatch['opportunity'];
        $assignmentMatch = $this->matchingService->matchInternshipAssignment($student, $internship);

        if ($assignmentMatch['outcome'] !== 'MATCHED') {
            return $this->errorRow([...$messages, $assignmentMatch['message']]);
        }

        $assignment = $assignmentMatch['assignment'];
        $changes = [];
        $confirmContext = ['assignment_id' => $assignment->id];
        $hasAnyRequestedChange = false;

        if (($data['status'] ?? null) !== null) {
            $hasAnyRequestedChange = true;
            $targetAssignmentStatus = $this->validationService->normalizeInternshipAssignmentStatus($data['status']);

            if ($targetAssignmentStatus === null) {
                return $this->errorRow([...$messages, sprintf('Unknown internship status "%s".', $data['status'])]);
            }

            $currentAssignmentStatus = $assignment->status;

            if ($currentAssignmentStatus === $targetAssignmentStatus) {
                $messages[] = sprintf('The internship assignment is already %s.', $currentAssignmentStatus->value);
            } elseif (! $currentAssignmentStatus->canTransitionTo($targetAssignmentStatus)) {
                return $this->errorRow([...$messages, sprintf('The internship assignment cannot move from %s to %s.', $currentAssignmentStatus->value, $targetAssignmentStatus->value)]);
            } else {
                $confirmContext['target_assignment_status'] = $targetAssignmentStatus->value;
                $changes[] = ['field' => 'assignment_status', 'label' => 'Internship Status', 'old_value' => $currentAssignmentStatus->value, 'new_value' => $targetAssignmentStatus->value];
            }
        }

        if (($data['ppo_status'] ?? null) !== null) {
            $hasAnyRequestedChange = true;
            $targetPpoStatus = $this->validationService->normalizePpoStatus($data['ppo_status']);

            if ($targetPpoStatus === null) {
                return $this->errorRow([...$messages, sprintf('Unknown PPO status "%s".', $data['ppo_status'])]);
            }

            $ppoRecord = $assignment->ppoRecord;

            if ($ppoRecord === null) {
                // Module spec section 15/5: never create a new PPO
                // record from an unmatched Excel row - a PPO's own
                // creation requires designation/offered_at this import
                // type has no columns for, and PPO is explicitly
                // optional (module spec section 15: "PPO is optional").
                $messages[] = 'No existing PPO record found for this assignment; the PPO Status column was ignored (PPO records cannot be created by this import).';
            } else {
                $currentPpoStatus = $ppoRecord->status;

                if ($currentPpoStatus === $targetPpoStatus) {
                    $messages[] = sprintf('The PPO record is already %s.', $currentPpoStatus->value);
                } elseif (! $currentPpoStatus->canTransitionTo($targetPpoStatus)) {
                    return $this->errorRow([...$messages, sprintf('The PPO record cannot move from %s to %s.', $currentPpoStatus->value, $targetPpoStatus->value)]);
                } else {
                    $confirmContext['ppo_record_id'] = $ppoRecord->id;
                    $confirmContext['target_ppo_status'] = $targetPpoStatus->value;
                    $changes[] = ['field' => 'ppo_status', 'label' => 'PPO Status', 'old_value' => $currentPpoStatus->value, 'new_value' => $targetPpoStatus->value];
                }
            }
        }

        if (! $hasAnyRequestedChange) {
            return $this->errorRow(['Neither Status nor PPO Status was supplied for this row.']);
        }

        if ($changes === []) {
            // Matched, but every requested change was a no-op/ignored -
            // there is nothing to confirm, but it is not a matching
            // failure either.
            return [
                'outcome' => ExcelRowOutcome::WARNING->value,
                'entity_type' => InternshipAssignment::class,
                'entity_id' => $assignment->id,
                'confirm_context' => null,
                'messages' => $messages === [] ? ['Nothing to update for this row.'] : $messages,
                'changes' => [],
            ];
        }

        return [
            'outcome' => $messages === [] ? ExcelRowOutcome::VALID->value : ExcelRowOutcome::WARNING->value,
            'entity_type' => InternshipAssignment::class,
            'entity_id' => $assignment->id,
            'confirm_context' => $confirmContext,
            'messages' => $messages,
            'changes' => $changes,
        ];
    }

    private function confirmInternshipStatusRow(ExcelImportRow $row, ExcelImportBatch $batch, User $actingUser): void
    {
        $context = $row->confirm_context;

        if ($context === null || $row->changes === []) {
            throw new ExcelImportException('This row has no confirmable change.');
        }

        $assignment = InternshipAssignment::find($context['assignment_id']);

        if ($assignment === null) {
            throw new ExcelImportException('The matched internship assignment no longer exists.');
        }

        if (isset($context['target_assignment_status'])) {
            $current = $assignment->status;
            $target = InternshipAssignmentStatus::from($context['target_assignment_status']);

            if (! $current->canTransitionTo($target)) {
                throw new ExcelImportException(sprintf('This assignment has since moved to %s and can no longer move to %s.', $current->value, $target->value));
            }

            match ($target) {
                InternshipAssignmentStatus::ACTIVE => $this->assignmentService->activate($assignment, $actingUser),
                InternshipAssignmentStatus::COMPLETED => $this->assignmentService->complete($assignment, $actingUser),
                InternshipAssignmentStatus::CANCELLED => $this->assignmentService->cancel($assignment, $actingUser, 'Updated via Excel import.'),
                InternshipAssignmentStatus::ASSIGNED => throw new ExcelImportException('An internship assignment can never be moved back to ASSIGNED.'),
            };

            $this->recordChange($batch, $row, InternshipAssignment::class, $assignment->id, 'status', $current->value, $target->value, $actingUser);
        }

        if (isset($context['ppo_record_id'], $context['target_ppo_status'])) {
            $ppoRecord = PpoRecord::find($context['ppo_record_id']);

            if ($ppoRecord === null) {
                throw new ExcelImportException('The matched PPO record no longer exists.');
            }

            $current = $ppoRecord->status;
            $target = $context['target_ppo_status'];

            if ($current->value === $target) {
                return;
            }

            $this->completionService->updatePpo($ppoRecord, $actingUser, ['status' => $target]);

            $this->recordChange($batch, $row, PpoRecord::class, $ppoRecord->id, 'status', $current->value, $target, $actingUser);
        }
    }

    // --- Interview Schedule -----------------------------------------------------------

    /**
     * @param array<string, ?string> $data
     */
    private function buildInterviewScheduleRow(array $data): array
    {
        $messages = [];

        $match = $this->matchingService->matchStudent($data['roll_number'] ?? null, null);

        if ($match['outcome'] !== 'MATCHED') {
            return $this->errorRow([$match['message']]);
        }

        $student = $match['student'];

        if ($this->matchingService->nameLooksMismatched($student, $data['student_name'] ?? null)) {
            $messages[] = 'The Excel name differs significantly from the matched student\'s name on record.';
        }

        $opportunityMatch = $this->matchingService->matchOpportunity($data['company'] ?? null, $data['job_role'] ?? null);

        if ($opportunityMatch['outcome'] !== 'MATCHED') {
            return $this->errorRow([...$messages, $opportunityMatch['message']]);
        }

        $applicationMatch = $this->matchingService->matchApplication($student, $opportunityMatch['opportunity']);

        if ($applicationMatch['outcome'] !== 'MATCHED') {
            return $this->errorRow([...$messages, $applicationMatch['message']]);
        }

        $application = $applicationMatch['application'];
        $target = $this->validationService->normalizeInterviewStatus($data['status'] ?? null);

        if ($target === null) {
            return $this->errorRow([...$messages, sprintf('Unknown interview status "%s".', $data['status'] ?? '')]);
        }

        $existing = $this->matchingService->findActiveInterview($application);

        $scheduledAt = null;

        if (in_array($target->value, ['SCHEDULED', 'RESCHEDULED'], true)) {
            if (($data['interview_date'] ?? null) === null) {
                return $this->errorRow([...$messages, 'Interview Date is required to schedule or reschedule an interview.']);
            }

            try {
                $scheduledAt = \Carbon\Carbon::parse($data['interview_date'].' '.($data['interview_time'] ?? '09:00'))->toIso8601String();
            } catch (Throwable) {
                return $this->errorRow([...$messages, 'Interview Date/Time could not be understood.']);
            }
        }

        $mode = $data['mode'] !== null ? strtoupper(trim($data['mode'])) : null;

        if ($mode !== null && ! in_array($mode, ['ONLINE', 'OFFLINE', 'HYBRID'], true)) {
            return $this->errorRow([...$messages, sprintf('Unknown interview mode "%s".', $data['mode'])]);
        }

        if ($existing === null) {
            if ($target->value !== 'SCHEDULED') {
                return $this->errorRow([...$messages, sprintf('No existing interview found for this application; %s requires an existing scheduled interview.', $target->value)]);
            }

            if (! in_array($application->status->value, ['SHORTLISTED', 'ASSESSMENT', 'INTERVIEW'], true)) {
                return $this->errorRow([...$messages, sprintf('An interview cannot be scheduled while the application is %s.', $application->status->value)]);
            }

            return [
                'outcome' => $messages === [] ? ExcelRowOutcome::VALID->value : ExcelRowOutcome::WARNING->value,
                'entity_type' => Application::class,
                'entity_id' => $application->id,
                'confirm_context' => [
                    'application_id' => $application->id,
                    'existing_interview_id' => null,
                    'target_status' => 'SCHEDULED',
                    'scheduled_at' => $scheduledAt,
                    'mode' => $mode ?? 'ONLINE',
                    'venue' => $data['venue'] ?? null,
                    'meeting_url' => $data['meeting_link'] ?? null,
                ],
                'messages' => $messages,
                'changes' => [['field' => 'status', 'label' => 'Interview', 'old_value' => null, 'new_value' => 'SCHEDULED']],
            ];
        }

        $current = $existing->status;

        if ($current === $target) {
            return $this->errorRow([...$messages, sprintf('This interview is already %s.', $current->value)]);
        }

        if (! $current->canTransitionTo($target)) {
            return $this->errorRow([...$messages, sprintf('This interview cannot move from %s to %s.', $current->value, $target->value)]);
        }

        return [
            'outcome' => $messages === [] ? ExcelRowOutcome::VALID->value : ExcelRowOutcome::WARNING->value,
            'entity_type' => Interview::class,
            'entity_id' => $existing->id,
            'confirm_context' => [
                'application_id' => $application->id,
                'existing_interview_id' => $existing->id,
                'target_status' => $target->value,
                'scheduled_at' => $scheduledAt,
                'mode' => $mode,
                'venue' => $data['venue'] ?? null,
                'meeting_url' => $data['meeting_link'] ?? null,
            ],
            'messages' => $messages,
            'changes' => [['field' => 'status', 'label' => 'Interview', 'old_value' => $current->value, 'new_value' => $target->value]],
        ];
    }

    private function confirmInterviewScheduleRow(ExcelImportRow $row, ExcelImportBatch $batch, User $actingUser): void
    {
        $context = $row->confirm_context;
        $application = Application::find($context['application_id']);

        if ($application === null) {
            throw new ExcelImportException('The matched application no longer exists.');
        }

        if ($context['existing_interview_id'] === null) {
            $existing = $this->matchingService->findActiveInterview($application);

            if ($existing !== null) {
                throw new ExcelImportException('An active interview now exists for this application; re-upload to update it instead.');
            }

            $round = Interview::where('application_id', $application->id)->count() + 1;

            $interview = $this->interviewService->create($application, [
                'round' => $round,
                'scheduled_at' => $context['scheduled_at'],
                'mode' => $context['mode'],
                'venue' => $context['venue'],
                'meeting_url' => $context['meeting_url'],
            ], $actingUser);

            $this->recordChange($batch, $row, Interview::class, $interview->id, 'status', null, 'SCHEDULED', $actingUser, isCreation: true);

            return;
        }

        $interview = Interview::find($context['existing_interview_id']);

        if ($interview === null) {
            throw new ExcelImportException('The matched interview no longer exists.');
        }

        $current = $interview->status;
        $targetValue = $context['target_status'];

        if (! $current->canTransitionTo(\App\Enums\InterviewStatus::from($targetValue))) {
            throw new ExcelImportException(sprintf('This interview has since moved to %s and can no longer move to %s.', $current->value, $targetValue));
        }

        match ($targetValue) {
            'COMPLETED' => $this->interviewService->complete($interview, $actingUser),
            'CANCELLED' => $this->interviewService->cancel($interview, $actingUser, 'Cancelled via Excel import.'),
            'RESCHEDULED' => $this->interviewService->reschedule($interview, [
                'scheduled_at' => $context['scheduled_at'],
                'mode' => $context['mode'],
                'venue' => $context['venue'],
                'meeting_url' => $context['meeting_url'],
            ], $actingUser),
            default => throw new ExcelImportException(sprintf('Unsupported interview transition to %s.', $targetValue)),
        };

        $this->recordChange($batch, $row, Interview::class, $interview->id, 'status', $current->value, $targetValue, $actingUser);
    }

    // --- Attendance ---------------------------------------------------------------------

    /**
     * @param array<string, ?string> $data
     */
    private function buildAttendanceRow(array $data): array
    {
        $messages = [];

        $match = $this->matchingService->matchStudent($data['roll_number'] ?? null, null);

        if ($match['outcome'] !== 'MATCHED') {
            return $this->errorRow([$match['message']]);
        }

        $student = $match['student'];

        if ($this->matchingService->nameLooksMismatched($student, $data['student_name'] ?? null)) {
            $messages[] = 'The Excel name differs significantly from the matched student\'s name on record.';
        }

        // The "Internship" column is a single free-text cell (its own
        // header aliases to "Company") - matched against this
        // student's OWN assignments, never the whole opportunities
        // table. See ExcelMatchingService::matchStudentInternshipAssignment()'s
        // own docblock for why this is not a matchOpportunity() call.
        $assignmentMatch = $this->matchingService->matchStudentInternshipAssignment($student, $data['internship'] ?? null);

        if ($assignmentMatch['outcome'] !== 'MATCHED') {
            return $this->errorRow([...$messages, $assignmentMatch['message']]);
        }

        $assignment = $assignmentMatch['assignment'];

        if ($assignment->status->value !== 'ACTIVE') {
            return $this->errorRow([...$messages, sprintf('Attendance can only be marked for an ACTIVE internship assignment (this one is %s).', $assignment->status->value)]);
        }

        $status = $this->validationService->normalizeAttendanceStatus($data['status'] ?? null);

        if ($status === null) {
            return $this->errorRow([...$messages, sprintf('Unknown attendance status "%s".', $data['status'] ?? '')]);
        }

        if (($data['attendance_date'] ?? null) === null) {
            return $this->errorRow([...$messages, 'Attendance Date is required.']);
        }

        if (Attendance::where('internship_assignment_id', $assignment->id)->whereDate('attendance_date', $data['attendance_date'])->exists()) {
            return $this->errorRow([...$messages, sprintf('Attendance for %s has already been marked for this assignment.', $data['attendance_date'])]);
        }

        return [
            'outcome' => $messages === [] ? ExcelRowOutcome::VALID->value : ExcelRowOutcome::WARNING->value,
            'entity_type' => InternshipAssignment::class,
            'entity_id' => $assignment->id,
            'confirm_context' => [
                'assignment_id' => $assignment->id,
                'attendance_date' => $data['attendance_date'],
                'status' => $status->value,
                'check_in' => $data['check_in'] ?? null,
                'check_out' => $data['check_out'] ?? null,
            ],
            'messages' => $messages,
            'changes' => [['field' => 'attendance', 'label' => 'Attendance', 'old_value' => null, 'new_value' => sprintf('%s on %s', $status->value, $data['attendance_date'])]],
        ];
    }

    private function confirmAttendanceRow(ExcelImportRow $row, ExcelImportBatch $batch, User $actingUser): void
    {
        $context = $row->confirm_context;
        $assignment = InternshipAssignment::find($context['assignment_id']);

        if ($assignment === null) {
            throw new ExcelImportException('The matched internship assignment no longer exists.');
        }

        $attendance = $this->attendanceService->markAttendance($assignment, $actingUser, [
            'status' => $context['status'],
            'attendance_date' => $context['attendance_date'],
            'check_in_time' => $context['check_in'],
            'check_out_time' => $context['check_out'],
        ]);

        $this->recordChange($batch, $row, Attendance::class, $attendance->id, 'status', null, $context['status'], $actingUser, isCreation: true);
    }

    // --- Student Master Data -------------------------------------------------------------

    /**
     * @var array<string, string> Excel column key => Student model field
     */
    private const STUDENT_UPDATABLE_FIELDS = [
        'department' => 'department_id',
        'batch' => 'batch_id',
        'year' => 'year',
        'phone' => 'phone',
        'personal_email' => 'personal_email',
        'cgpa' => 'cgpa',
        'backlogs' => 'backlogs',
        'linkedin' => 'linkedin_url',
        'github' => 'github_url',
        'portfolio' => 'portfolio_url',
    ];

    /**
     * Module spec section 17 - deliberately covers only plain scalar
     * Student fields matched via Roll Number. Skills/Projects/
     * Certifications/Experience are NOT included: those are their own
     * structured relational tables (StudentSkill/StudentProject/
     * StudentCertification/StudentExperience), each with its own
     * shape well beyond a single Excel cell of free text, and bulk-
     * writing into them from an unstructured column would itself be
     * exactly the "arbitrary Excel column modifying arbitrary database
     * structure" the module spec's section 2 explicitly forbids. This
     * is a scoped, documented decision - see the final delivery
     * report. `Name` updates User.name (never User.email - "college
     * email" is never overwritten by this import, matching the module
     * spec's explicit "Do not overwrite immutable college email").
     *
     * @param array<string, ?string> $data
     */
    private function buildStudentMasterRow(array $data): array
    {
        $match = $this->matchingService->matchStudent($data['roll_number'] ?? null, null);

        if ($match['outcome'] !== 'MATCHED') {
            return $this->errorRow([$match['message']]);
        }

        $student = $match['student'];
        $changes = [];
        $messages = [];

        if (($data['name'] ?? null) !== null) {
            $newName = trim($data['name']);
            $currentName = $student->user?->name;

            if ($newName !== '' && $newName !== $currentName) {
                $changes[] = ['field' => 'name', 'label' => 'Name', 'old_value' => $currentName, 'new_value' => $newName];
            }
        }

        foreach (self::STUDENT_UPDATABLE_FIELDS as $excelKey => $studentField) {
            if (($data[$excelKey] ?? null) === null) {
                continue;
            }

            $result = $this->resolveStudentFieldValue($excelKey, $studentField, $data[$excelKey]);

            if ($result === null) {
                $messages[] = sprintf('Could not understand "%s" for %s; that column was left unchanged.', $data[$excelKey], ucfirst($excelKey));

                continue;
            }

            [$resolvedValue, $displayValue] = $result;
            $currentValue = $student->{$studentField};
            $currentDisplay = $currentValue instanceof \BackedEnum ? $currentValue->value : $currentValue;

            if ((string) $currentDisplay !== (string) $displayValue) {
                $changes[] = ['field' => $studentField, 'label' => ucfirst(str_replace('_', ' ', $excelKey)), 'old_value' => $currentDisplay, 'new_value' => $displayValue, '_resolved' => $resolvedValue];
            }
        }

        if ($changes === []) {
            return $this->errorRow(array_merge(['No recognized changes were found for this student.'], $messages));
        }

        return [
            'outcome' => $messages === [] ? ExcelRowOutcome::VALID->value : ExcelRowOutcome::WARNING->value,
            'entity_type' => Student::class,
            'entity_id' => $student->id,
            'confirm_context' => null,
            'messages' => $messages,
            'changes' => array_map(fn ($c) => array_diff_key($c, ['_resolved' => true]), $changes),
        ];
    }

    /**
     * Department is matched by code or name; Batch by exact name or by
     * end_year (a company/placement-cell sheet commonly writes the
     * graduating year alone, e.g. "2027", rather than this codebase's
     * full "2022-2026" Batch.name range) - both resolved once per call
     * rather than cached, since Student Master imports are the
     * smallest-volume import type in practice (one row per student,
     * not one row per event).
     *
     * @return array{0: mixed, 1: string}|null [normalized value to persist, display string for the change log]
     */
    private function resolveStudentFieldValue(string $excelKey, string $studentField, string $rawValue): ?array
    {
        $rawValue = trim($rawValue);

        return match ($studentField) {
            'department_id' => $this->resolveDepartment($rawValue),
            'batch_id' => $this->resolveBatch($rawValue),
            'year' => is_numeric($rawValue) ? [(int) $rawValue, $rawValue] : null,
            'cgpa' => is_numeric($rawValue) ? [(float) $rawValue, $rawValue] : null,
            'backlogs' => is_numeric($rawValue) ? [(int) $rawValue, $rawValue] : null,
            default => [$rawValue, $rawValue],
        };
    }

    private function resolveDepartment(string $rawValue): ?array
    {
        $department = \App\Models\Department::query()
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($rawValue)])
            ->orWhereRaw('UPPER(name) = ?', [mb_strtoupper($rawValue)])
            ->first();

        return $department !== null ? [$department->id, $department->name] : null;
    }

    private function resolveBatch(string $rawValue): ?array
    {
        $batch = \App\Models\Batch::query()->whereRaw('UPPER(name) = ?', [mb_strtoupper($rawValue)])->first();

        if ($batch === null && is_numeric($rawValue)) {
            $batch = \App\Models\Batch::query()->where('end_year', (int) $rawValue)->first();
        }

        return $batch !== null ? [$batch->id, $batch->name] : null;
    }

    private function confirmStudentMasterRow(ExcelImportRow $row, ExcelImportBatch $batch, User $actingUser): void
    {
        $student = Student::find($row->entity_id);

        if ($student === null) {
            throw new ExcelImportException('The matched student no longer exists.');
        }

        $studentUpdates = [];
        $userUpdates = [];

        foreach ($row->changes as $change) {
            if ($change['field'] === 'name') {
                $userUpdates['name'] = $change['new_value'];

                continue;
            }

            $excelKey = array_search($change['field'], self::STUDENT_UPDATABLE_FIELDS, true);
            $resolved = $excelKey !== false ? $this->resolveStudentFieldValue($excelKey, $change['field'], (string) $change['new_value']) : null;

            if ($resolved === null) {
                continue;
            }

            $studentUpdates[$change['field']] = $resolved[0];
        }

        DB::transaction(function () use ($student, $studentUpdates, $userUpdates, $batch, $row, $actingUser) {
            foreach ($row->changes as $change) {
                $entityType = $change['field'] === 'name' ? User::class : Student::class;
                $entityId = $change['field'] === 'name' ? $student->user_id : $student->id;
                $currentValue = $change['field'] === 'name'
                    ? $student->user?->name
                    : ($student->{$change['field']} instanceof \BackedEnum ? $student->{$change['field']}->value : $student->{$change['field']});

                $this->recordChange($batch, $row, $entityType, $entityId, $change['field'], (string) $currentValue, (string) $change['new_value'], $actingUser);
            }

            if ($studentUpdates !== []) {
                $student->update($studentUpdates);
            }

            if ($userUpdates !== [] && $student->user !== null) {
                $student->user->update($userUpdates);
            }
        });
    }

    // =========================================================================
    // CONFIRM
    // =========================================================================

    /**
     * @param array<int, int>|null $rowIds when null, every confirmable row on the batch is processed
     * @return array{applied: int, failed: int, skipped_errors: int}
     */
    public function confirm(ExcelImportBatch $batch, User $actingUser, ?array $rowIds = null): array
    {
        if ($batch->status !== ExcelImportBatchStatus::READY_FOR_CONFIRMATION) {
            throw new ExcelImportException('This batch is not ready for confirmation.');
        }

        if ($batch->import_type === ExcelImportType::STUDENT_PLACEMENT_STATUS) {
            return $this->confirmPlacementStatusBatch($batch, $actingUser);
        }

        $query = $batch->rows()->whereIn('outcome', [ExcelRowOutcome::VALID->value, ExcelRowOutcome::WARNING->value]);

        if ($rowIds !== null) {
            $query->whereIn('id', $rowIds);
        }

        $targetRows = $query->get();

        if ($targetRows->isEmpty()) {
            throw new ExcelImportException('There are no rows eligible for confirmation.');
        }

        $applied = 0;
        $failed = 0;

        try {
            DB::transaction(function () use ($targetRows, $batch, $actingUser, &$applied, &$failed) {
                foreach ($targetRows as $row) {
                    try {
                        match (true) {
                            $batch->import_type->isApplicationStatusFamily() => $this->confirmApplicationStatusRow($row, $batch, $actingUser),
                            $batch->import_type === ExcelImportType::INTERNSHIP_STATUS => $this->confirmInternshipStatusRow($row, $batch, $actingUser),
                            $batch->import_type === ExcelImportType::INTERVIEW_SCHEDULE => $this->confirmInterviewScheduleRow($row, $batch, $actingUser),
                            $batch->import_type === ExcelImportType::ATTENDANCE => $this->confirmAttendanceRow($row, $batch, $actingUser),
                            $batch->import_type === ExcelImportType::STUDENT_MASTER => $this->confirmStudentMasterRow($row, $batch, $actingUser),
                        };

                        $row->update(['applied' => true, 'applied_at' => now()]);
                        $applied++;
                    } catch (ApplicationException|InterviewException|InternshipAssignmentException|PpoRecordException|AttendanceException|ExcelImportException $e) {
                        // Expected, row-scoped business rejection - see
                        // this class's own docblock ("PARTIAL SUCCESS
                        // vs. CRITICAL FAILURE"). Never rethrown: the
                        // transaction continues with the remaining rows.
                        $row->update(['applied' => false, 'apply_error' => $e->getMessage()]);
                        $failed++;
                    }
                }

                if ($applied === 0) {
                    throw new ExcelImportException('None of the selected rows could be confirmed - every one failed re-validation against current data. Please re-upload and review.');
                }

                $batch->update([
                    'status' => ExcelImportBatchStatus::CONFIRMED,
                    'confirmed_by' => $actingUser->id,
                    'confirmed_at' => now(),
                ]);
            });
        } catch (ExcelImportException $e) {
            $batch->update(['status' => ExcelImportBatchStatus::FAILED, 'error_message' => $e->getMessage()]);

            throw $e;
        } catch (Throwable $e) {
            $batch->update(['status' => ExcelImportBatchStatus::FAILED, 'error_message' => 'An unexpected error occurred while confirming this import; no changes were saved.']);

            throw new ExcelImportException('An unexpected error occurred while confirming this import; no changes were saved.', status: 500);
        }

        return ['applied' => $applied, 'failed' => $failed, 'skipped_errors' => $targetRows->count() - $applied - $failed];
    }

    /** Placement status files are atomic: every row is checked before any is applied. */
    private function confirmPlacementStatusBatch(ExcelImportBatch $batch, User $actingUser): array
    {
        $rows = $batch->rows()->orderBy('row_number')->get();
        $rowNumbersByIdentifier = [];
        foreach ($rows as $row) {
            $identifier = $row->raw_row['roll_number'] ?? null;
            $normalized = mb_strtoupper(preg_replace('/\\s+/', ' ', trim((string) $identifier)) ?? '');
            if ($normalized !== '') $rowNumbersByIdentifier[$normalized][] = $row->row_number;
        }

        $errors = [];
        foreach ($rowNumbersByIdentifier as $identifier => $rowNumbers) {
            if (count($rowNumbers) < 2) continue;
            foreach ($rowNumbers as $rowNumber) {
                $errors[] = ['code' => 'DUPLICATE_STUDENT', 'message' => sprintf(
                    'Row %d: duplicate student identifier "%s" also appears on rows %s.',
                    $rowNumber,
                    $identifier,
                    implode(', ', $rowNumbers),
                )];
            }
        }

        $contexts = [];
        foreach ($rows as $row) {
            if ($row->outcome === ExcelRowOutcome::ERROR) {
                foreach ($row->messages ?? [] as $message) {
                    $errors[] = ['code' => 'INVALID_ROW', 'message' => "Row {$row->row_number}: {$message}"];
                }
                continue;
            }

            $fresh = $this->buildStudentPlacementStatusRow($row->raw_row ?? []);
            if ($fresh['outcome'] !== ExcelRowOutcome::VALID->value || $fresh['entity_id'] !== $row->entity_id) {
                foreach ($fresh['messages'] ?: ['The student data no longer matches the preview. Upload and review the file again.'] as $message) {
                    $errors[] = ['code' => 'STALE_OR_INVALID_ROW', 'message' => "Row {$row->row_number}: {$message}"];
                }
                continue;
            }

            $preview = $row->confirm_context ?? [];
            if (($fresh['confirm_context']['from_status'] ?? null) !== ($preview['from_status'] ?? null)
                || ! $this->placementDetailsMatch(
                    $fresh['confirm_context']['old_details'] ?? null,
                    $preview['old_details'] ?? null,
                )) {
                $errors[] = ['code' => 'STALE_ROW', 'message' => "Row {$row->row_number}: placement status or details changed after preview. Upload and review the file again."];
                continue;
            }

            $contexts[$row->id] = $preview;
        }

        if ($errors !== []) {
            $this->failPlacementStatusBatch($batch, $rows, $errors);
            throw new ExcelImportException('Placement status import was not applied. Correct the listed rows and upload the file again.', $errors);
        }

        try {
            DB::transaction(function () use ($batch, $rows, $actingUser, $contexts) {
                foreach ($rows as $row) {
                    // Check again under the transaction immediately before each write;
                    // any failure escapes and rolls back the entire placement batch.
                    $student = Student::query()->lockForUpdate()->find($row->entity_id);
                    $context = $contexts[$row->id];
                    if ($student === null
                        || $student->placement_status !== $context['from_status']
                        || ! $this->placementDetailsMatch(
                            $this->placementDetailsForImport($student),
                            $context['old_details'] ?? null,
                        )) {
                        throw new ExcelImportException("Row {$row->row_number}: student data changed during confirmation. No placement changes were saved.", [[
                            'code' => 'STALE_ROW', 'message' => "Row {$row->row_number}: student data changed during confirmation. No placement changes were saved.",
                        ]]);
                    }

                    $this->placementStatusService->update($student, $context['target_status'], $actingUser, $context['details'], $context['reason']);
                    $this->recordChange($batch, $row, Student::class, $student->id, 'placement_status', $context['from_status'], $context['target_status'], $actingUser);
                    $row->update(['applied' => true, 'applied_at' => now(), 'apply_error' => null]);
                }

                $batch->update([
                    'status' => ExcelImportBatchStatus::CONFIRMED,
                    'confirmed_by' => $actingUser->id,
                    'confirmed_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            $message = $e instanceof ExcelImportException
                ? $e->getMessage()
                : 'Placement status import failed during confirmation. No placement changes were saved.';
            $errors = $e instanceof ExcelImportException && $e->reasons() !== []
                ? $e->reasons()
                : [['code' => 'CONFIRMATION_FAILED', 'message' => $message]];
            $this->failPlacementStatusBatch($batch, $rows, $errors);
            throw new ExcelImportException($message, $errors, $e instanceof ExcelImportException ? $e->status() : 422);
        }

        return ['applied' => $rows->count(), 'failed' => 0, 'skipped_errors' => 0];
    }

    /** @return array<string, mixed> */
    private function placementDetailsForImport(Student $student): array
    {
        return [
            'placement_company' => $student->placement_company,
            'placement_job_role' => $student->placement_job_role,
            'placement_package' => $student->placement_package === null ? null : (string) $student->placement_package,
            'placement_date' => $student->placement_date?->toDateString(),
        ];
    }

    /**
     * MySQL's native JSON type may return object keys in a different order
     * than the PHP array used to build the preview. Compare the known fields
     * by name and normalize DECIMAL representations while keeping values
     * otherwise strict, so genuine stale edits are still rejected.
     */
    private function placementDetailsMatch(?array $left, ?array $right): bool
    {
        $normalize = static function (?array $details): array {
            $package = $details['placement_package'] ?? null;

            return [
                'placement_company' => $details['placement_company'] ?? null,
                'placement_job_role' => $details['placement_job_role'] ?? null,
                'placement_package' => $package === null ? null : number_format((float) $package, 2, '.', ''),
                'placement_date' => $details['placement_date'] ?? null,
            ];
        };

        return $normalize($left) === $normalize($right);
    }

    /** @param iterable<ExcelImportRow> $rows
     * @param list<array{code: string, message: string}> $errors
     */
    private function failPlacementStatusBatch(ExcelImportBatch $batch, iterable $rows, array $errors): void
    {
        $messagesByRow = [];
        foreach ($errors as $error) {
            if (preg_match('/^Row (\\d+):/', $error['message'], $match)) $messagesByRow[(int) $match[1]][] = $error['message'];
        }
        foreach ($rows as $row) {
            if (isset($messagesByRow[$row->row_number])) {
                $row->update(['applied' => false, 'apply_error' => implode(' ', $messagesByRow[$row->row_number])]);
            }
        }
        $batch->update(['status' => ExcelImportBatchStatus::FAILED, 'error_message' => 'Placement status import was not applied. Correct the listed rows and upload the file again.']);
    }

    private function recordChange(
        ExcelImportBatch $batch,
        ExcelImportRow $row,
        string $entityType,
        int $entityId,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        User $actingUser,
        bool $isCreation = false,
    ): void {
        ExcelImportChange::create([
            'import_batch_id' => $batch->id,
            'excel_import_row_id' => $row->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'changed_by' => $actingUser->id,
            'is_creation' => $isCreation,
        ]);
    }

    // =========================================================================
    // ROLLBACK
    // =========================================================================

    /**
     * Module spec section 13. Reverts every un-reverted
     * ExcelImportChange this CONFIRMED batch produced, going back
     * through the SAME domain service that made the original change -
     * never a raw DB write, for rollback any more than for import
     * itself (see this class's own docblock). Three outcomes per
     * change:
     *
     *  REVERTED         - the field's current value still equals what
     *                      this import set it to, AND the reverse
     *                      transition is legal under the domain
     *                      service's own business rules -> reverted,
     *                      and the reversal is itself recorded as a
     *                      new ExcelImportChange (module spec: "Rollback
     *                      must itself be audited"). IMPORTANT, and
     *                      worth stating plainly rather than leaving
     *                      implicit: every status-typed entity this
     *                      class writes to (Application, PpoRecord,
     *                      Interview, InternshipAssignment) has a
     *                      STRICTLY FORWARD-ONLY canTransitionTo()
     *                      graph in this codebase - there is no A->B
     *                      edge anywhere that also has a B->A edge. A
     *                      REVERTED outcome is therefore, in practice,
     *                      reachable only for Student Master's plain
     *                      scalar field changes (department/batch/year/
     *                      phone/.../name) - a status-field rollback
     *                      will realistically always fall through to
     *                      CONFLICT below, not because rollback is
     *                      broken, but because "undo this admin
     *                      decision" and "an admin decision, once made,
     *                      moves the record forward" are two rules this
     *                      codebase's pre-existing services already
     *                      hold in tension, and this class does not
     *                      relax that tension just because the change
     *                      came from Excel. See the final delivery
     *                      report's "known limitations" section.
     *  CONFLICT          - either the field's current value no longer
     *                      matches what this import set (something
     *                      else changed it since - module spec's own
     *                      example: SELECTED -> REJECTED after our
     *                      INTERVIEW -> SELECTED import), OR the
     *                      reverse transition is not legal under the
     *                      target enum's canTransitionTo() (the far
     *                      more common case in practice - see REVERTED
     *                      above). Either way: untouched, flagged for
     *                      manual review, batch stays CONFIRMED.
     *  NOT_ROLLBACKABLE  - the change represents a brand-new record
     *                      (is_creation = true, e.g. a Attendance row
     *                      or a freshly-scheduled Interview) - none of
     *                      this codebase's domain services expose a
     *                      delete path for these, so there is no
     *                      service-layer reversal to call; reported
     *                      distinctly from CONFLICT since this is a
     *                      structural limitation, not a data race.
     *
     * The batch only flips to ROLLED_BACK when EVERY change reverted
     * cleanly (zero conflicts, zero not-rollbackable) - a partial
     * rollback intentionally does not claim a status the module spec
     * never defined for it.
     *
     * @return array{reverted: int, conflicts: int, not_rollbackable: int, details: list<array{change_id: int, entity_type: string, entity_id: int, field: string, result: string, message: ?string}>}
     */
    public function rollback(ExcelImportBatch $batch, User $actingUser): array
    {
        if ($batch->status !== ExcelImportBatchStatus::CONFIRMED) {
            throw new ExcelImportException('Only a confirmed import can be rolled back.');
        }

        $changes = $batch->changes()->whereNull('rolled_back_at')->whereNull('is_rollback_of')->get();

        if ($changes->isEmpty()) {
            throw new ExcelImportException('This batch has no changes left to roll back.');
        }

        $reverted = 0;
        $conflicts = 0;
        $notRollbackable = 0;
        $details = [];

        DB::transaction(function () use ($changes, $batch, $actingUser, &$reverted, &$conflicts, &$notRollbackable, &$details) {
            foreach ($changes as $change) {
                $outcome = $this->attemptRevert($change, $actingUser);

                $details[] = [
                    'change_id' => $change->id,
                    'entity_type' => $change->entity_type,
                    'entity_id' => $change->entity_id,
                    'field' => $change->field,
                    'result' => $outcome['result'],
                    'message' => $outcome['message'],
                ];

                match ($outcome['result']) {
                    'REVERTED' => $reverted++,
                    'CONFLICT' => $conflicts++,
                    'NOT_ROLLBACKABLE' => $notRollbackable++,
                };

                if ($outcome['result'] === 'REVERTED') {
                    $change->update(['rolled_back_at' => now()]);

                    ExcelImportChange::create([
                        'import_batch_id' => $batch->id,
                        'excel_import_row_id' => null,
                        'entity_type' => $change->entity_type,
                        'entity_id' => $change->entity_id,
                        'field' => $change->field,
                        'old_value' => $change->new_value,
                        'new_value' => $change->old_value,
                        'changed_by' => $actingUser->id,
                        'is_creation' => false,
                        'is_rollback_of' => $change->id,
                    ]);
                }
            }

            if ($conflicts === 0 && $notRollbackable === 0) {
                $batch->update([
                    'status' => ExcelImportBatchStatus::ROLLED_BACK,
                    'rolled_back_by' => $actingUser->id,
                    'rolled_back_at' => now(),
                ]);
            }
        });

        return ['reverted' => $reverted, 'conflicts' => $conflicts, 'not_rollbackable' => $notRollbackable, 'details' => $details];
    }

    /**
     * @return array{result: string, message: ?string}
     */
    private function attemptRevert(ExcelImportChange $change, User $actingUser): array
    {
        if ($change->is_creation) {
            return ['result' => 'NOT_ROLLBACKABLE', 'message' => 'This change created a new record; no delete capability exists to reverse it. Reverse it manually if required.'];
        }

        try {
            return match ($change->entity_type) {
                Application::class => $this->revertApplication($change, $actingUser),
                InternshipAssignment::class => $this->revertInternshipAssignment($change, $actingUser),
                PpoRecord::class => $this->revertPpo($change, $actingUser),
                Interview::class => $this->revertInterview($change, $actingUser),
                Student::class => $change->field === 'placement_status'
                    ? $this->revertStudentPlacementStatus($change, $actingUser)
                    : $this->revertScalarField($change, $actingUser),
                User::class => $this->revertScalarField($change, $actingUser),
                default => ['result' => 'CONFLICT', 'message' => 'Unsupported entity type for rollback.'],
            };
        } catch (Throwable $e) {
            return ['result' => 'CONFLICT', 'message' => $e->getMessage()];
        }
    }

    private function revertStudentPlacementStatus(ExcelImportChange $change, User $actingUser): array
    {
        $student = Student::find($change->entity_id);
        if ($student === null || $student->placement_status !== $change->new_value) {
            return ['result' => 'CONFLICT', 'message' => 'The student placement status no longer holds the value this import set.'];
        }
        $history = StudentPlacementStatusHistory::query()
            ->where('student_id', $student->id)
            ->where('to_status', $change->new_value)
            ->where('changed_by', $change->changed_by)
            ->where('changed_at', '<=', $change->created_at)
            ->latest('changed_at')->latest('id')->first();
        if ($history === null) return ['result' => 'CONFLICT', 'message' => 'The placement change history needed for rollback could not be found.'];

        $oldDetails = $history->old_details ?? [];
        $details = [
            'placement_company' => $oldDetails['placement_company'] ?? null,
            'placement_job_role' => $oldDetails['placement_job_role'] ?? null,
            'placement_package' => $oldDetails['placement_package'] ?? null,
            'placement_date' => $oldDetails['placement_date'] ?? null,
        ];
        $this->placementStatusService->update($student, $history->from_status ?? 'UNKNOWN', $actingUser, $details, 'Reverted by Excel import rollback.');
        return ['result' => 'REVERTED', 'message' => null];
    }

    private function revertApplication(ExcelImportChange $change, User $actingUser): array
    {
        $application = Application::find($change->entity_id);

        if ($application === null || $application->status->value !== $change->new_value) {
            return ['result' => 'CONFLICT', 'message' => 'The application no longer holds the value this import set - something else changed it since.'];
        }

        $target = ApplicationStatus::from($change->old_value);

        if (! $application->status->canTransitionTo($target)) {
            return ['result' => 'CONFLICT', 'message' => sprintf('The application cannot be moved back to %s under current business rules.', $target->value)];
        }

        $this->applicationService->updateStatus($application, $target, $actingUser, 'Reverted by Excel import rollback.');

        return ['result' => 'REVERTED', 'message' => null];
    }

    private function revertInternshipAssignment(ExcelImportChange $change, User $actingUser): array
    {
        $assignment = InternshipAssignment::find($change->entity_id);

        if ($assignment === null || $assignment->status->value !== $change->new_value) {
            return ['result' => 'CONFLICT', 'message' => 'The internship assignment no longer holds the value this import set.'];
        }

        $target = InternshipAssignmentStatus::from($change->old_value);

        if (! $assignment->status->canTransitionTo($target)) {
            return ['result' => 'CONFLICT', 'message' => 'No reverse transition exists in current business rules for this assignment status change.'];
        }

        match ($target) {
            InternshipAssignmentStatus::ACTIVE => $this->assignmentService->activate($assignment, $actingUser),
            InternshipAssignmentStatus::COMPLETED => $this->assignmentService->complete($assignment, $actingUser),
            InternshipAssignmentStatus::CANCELLED => $this->assignmentService->cancel($assignment, $actingUser, 'Reverted by Excel import rollback.'),
            InternshipAssignmentStatus::ASSIGNED => throw new ExcelImportException('unreachable'),
        };

        return ['result' => 'REVERTED', 'message' => null];
    }

    private function revertPpo(ExcelImportChange $change, User $actingUser): array
    {
        $ppoRecord = PpoRecord::find($change->entity_id);

        if ($ppoRecord === null || $ppoRecord->status->value !== $change->new_value) {
            return ['result' => 'CONFLICT', 'message' => 'The PPO record no longer holds the value this import set.'];
        }

        $target = \App\Enums\PpoStatus::from($change->old_value);

        if (! $ppoRecord->status->canTransitionTo($target)) {
            return ['result' => 'CONFLICT', 'message' => 'No reverse transition exists in current business rules for this PPO status change.'];
        }

        $this->completionService->updatePpo($ppoRecord, $actingUser, ['status' => $target->value]);

        return ['result' => 'REVERTED', 'message' => null];
    }

    private function revertInterview(ExcelImportChange $change, User $actingUser): array
    {
        $interview = Interview::find($change->entity_id);

        if ($interview === null || $interview->status->value !== $change->new_value) {
            return ['result' => 'CONFLICT', 'message' => 'The interview no longer holds the value this import set.'];
        }

        return ['result' => 'CONFLICT', 'message' => 'No reverse transition exists in current business rules for this interview status change (InterviewStatus has no path back to a prior status).'];
    }

    private function revertScalarField(ExcelImportChange $change, User $actingUser): array
    {
        $model = $change->entity_type === User::class ? User::find($change->entity_id) : Student::find($change->entity_id);

        if ($model === null) {
            return ['result' => 'CONFLICT', 'message' => 'The record no longer exists.'];
        }

        $currentValue = $model->{$change->field};
        $currentDisplay = $currentValue instanceof \BackedEnum ? $currentValue->value : (string) $currentValue;

        if ($currentDisplay !== (string) $change->new_value) {
            return ['result' => 'CONFLICT', 'message' => 'This field no longer holds the value this import set - something else changed it since.'];
        }

        $model->update([$change->field => $change->old_value]);

        return ['result' => 'REVERTED', 'message' => null];
    }

    // =========================================================================
    // helpers
    // =========================================================================

    /**
     * @return array{outcome: string, entity_type: null, entity_id: null, confirm_context: null, messages: list<string>, changes: list<array>}
     */
    private function errorRow(array $messages): array
    {
        return [
            'outcome' => ExcelRowOutcome::ERROR->value,
            'entity_type' => null,
            'entity_id' => null,
            'confirm_context' => null,
            'messages' => $messages,
            'changes' => [],
        ];
    }

    /**
     * @return array{stored_filename: string, storage_path: string}
     */
    private function storeFile(UploadedFile $file): array
    {
        $storedFilename = Str::random(40).'.'.$file->extension();
        $storagePath = $file->storeAs('excel-imports', $storedFilename, self::DISK);

        if ($storagePath === false) {
            throw new ExcelImportException('Could not store the uploaded file. Please try again.', status: 500);
        }

        return ['stored_filename' => $storedFilename, 'storage_path' => $storagePath];
    }
}
