<?php

namespace App\Services\StudentImport;

use App\Enums\AcademicRecordSource;
use App\Enums\ExcelImportBatchStatus;
use App\Enums\ExcelImportType;
use App\Enums\ExcelRowOutcome;
use App\Enums\RoleName;
use App\Exceptions\ExcelImportException;
use App\Jobs\ConfirmStudentImport;
use App\Models\Batch;
use App\Models\ExcelImportBatch;
use App\Models\ExcelImportChange;
use App\Models\ExcelImportRow;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\PasswordService;
use App\Services\Student\AcademicCalendar;
use App\Services\Student\AcademicRecordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Super Admin > Student Data: importing the college's student file
 * (STUDENTS) and semester results updates (ACADEMIC_UPDATE).
 *
 * Same pipeline and tables as the Excel Sync Center - upload -> preview ->
 * confirm -> rollback on excel_import_batches/rows/changes - with the
 * differences this data needs:
 *  - every sheet is read, with loose header matching (StudentFileReader);
 *  - rows are cleaned (StudentRowCleaner) and sorted into New / Updated /
 *    Unchanged / Error (plus Warnings, which are New or Updated rows with
 *    something to look at);
 *  - confirming runs as a queued job with progress (ConfirmStudentImport)
 *    because hashing thousands of first passwords is slow;
 *  - the uploaded file is deleted once the import is confirmed or
 *    cancelled.
 *
 * Re-importing never erases: a blank cell keeps the current value, and a
 * student's password and their own fields (LinkedIn, portfolio, skills,
 * personal email, address, resume) are never touched.
 */
class StudentImportService
{
    private const DISK = 'local';

    private const CHUNK = 500;

    /** Results-update rows flagged for a second look. */
    private const BIG_CGPA_CHANGE = 2.0;

    private const BIG_BACKLOG_DROP = 5;

    /** students/users fields an import may set, with their preview label. */
    private const FIELDS = [
        'name' => 'Name',
        'year_of_joining' => 'Year of Joining',
        'gender' => 'Gender',
        'department_id' => 'Department',
        'section' => 'Section',
        'phone' => 'Mobile No',
        'cgpa' => 'CGPA',
        'backlogs' => 'Backlogs',
        'ssc_score' => '10th',
        'ssc_scale' => '10th scale',
        'inter_score' => 'Inter/Diploma',
        'inter_type' => 'Inter or Diploma',
        'entry_type' => 'Entry type',
        'program' => 'Program',
        'batch_id' => 'Batch',
        'year' => 'Current year',
    ];

    public function __construct(
        private readonly StudentFileReader $reader,
        private readonly AcademicCalendar $calendar,
        private readonly AcademicRecordService $academicRecords,
        private readonly AuditLogger $audit,
    ) {}

    // =========================================================================
    // UPLOAD + PREVIEW
    // =========================================================================

    public function upload(UploadedFile $file, ExcelImportType $type, User $actor, ?string $semesterLabel = null): ExcelImportBatch
    {
        $storedFilename = Str::random(40).'.'.$file->extension();
        $storagePath = $file->storeAs('student-imports', $storedFilename, self::DISK);

        if ($storagePath === false) {
            throw new ExcelImportException('Could not store the uploaded file. Please try again.', status: 500);
        }

        $batch = ExcelImportBatch::create([
            'import_type' => $type,
            'uploaded_by' => $actor->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'storage_path' => $storagePath,
            'status' => ExcelImportBatchStatus::UPLOADED,
            'summary' => ['semester_label' => $semesterLabel],
        ]);

        try {
            $this->analyse($batch);
        } catch (Throwable $e) {
            $message = $e instanceof ExcelImportException ? $e->getMessage() : 'An unexpected error occurred while reading this file.';
            $batch->update(['status' => ExcelImportBatchStatus::FAILED, 'error_message' => $message]);
            Storage::disk(self::DISK)->delete($storagePath);

            throw $e instanceof ExcelImportException ? $e : new ExcelImportException($message, status: 500);
        }

        $this->audit->record('student.import_uploaded', "Uploaded {$type->label()} file", $batch, $this->countsFor($batch), $actor);

        return $batch->fresh();
    }

    /**
     * Re-reads the stored file and rebuilds the preview - after adding a
     * missing department or alias, for example. Only before confirmation.
     */
    public function recheck(ExcelImportBatch $batch): ExcelImportBatch
    {
        if (! in_array($batch->status, [ExcelImportBatchStatus::READY_FOR_CONFIRMATION, ExcelImportBatchStatus::VALIDATED], true)) {
            throw new ExcelImportException('Only an import that has not been confirmed or cancelled can be re-checked.');
        }

        $this->analyse($batch);

        return $batch->fresh();
    }

    private function analyse(ExcelImportBatch $batch): void
    {
        $isStudents = $batch->import_type === ExcelImportType::STUDENTS;
        $required = $isStudents ? ['roll_number', 'name', 'department', 'section'] : ['roll_number'];

        $parsed = $this->reader->read(Storage::disk(self::DISK)->path($batch->storage_path), $required);

        if (! $isStudents) {
            foreach ($parsed['sheets'] as $sheet) {
                if (! $sheet['skipped'] && ! in_array('cgpa', $sheet['columns'], true) && ! in_array('backlogs', $sheet['columns'], true)) {
                    throw new ExcelImportException("Sheet \"{$sheet['name']}\" has no CGPA or No. of Backlogs column.");
                }
            }
        }

        $cleaner = new StudentRowCleaner;
        $rolls = [];
        $cleaned = [];

        foreach ($parsed['rows'] as $entry) {
            $result = $isStudents ? $cleaner->cleanStudent($entry['data']) : $cleaner->cleanAcademic($entry['data']);
            $cleaned[] = [$entry, $result];
            if (! $result['skip']) {
                $rolls[] = $result['values']['roll_number'];
            }
        }

        $sheetNotes = $this->collapseSheetWideCgpaProblems($cleaned);

        $existing = $this->existingStudents($rolls);
        $semesterDefault = $batch->summary['semester_label'] ?? null;

        $seen = [];
        $rowsToInsert = [];
        $sheetCounts = [];
        $unknownDepartments = [];
        $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0, 'warning' => 0];
        $validRows = 0;
        $now = now();

        foreach ($cleaned as $index => [$entry, $result]) {
            $roll = $result['values']['roll_number'];

            if (! $result['skip'] && isset($seen[$roll])) {
                $result['skip'] = true;
                $result['errors'][] = "Duplicate JNTU No - already on {$seen[$roll]}.";
            }
            if (! $result['skip']) {
                $seen[$roll] = "{$entry['sheet']} row {$entry['sheet_row']}";
            }

            $student = $existing->get($roll);
            $built = $isStudents
                ? $this->buildStudentRow($result, $student)
                : $this->buildAcademicRow($result, $student, $semesterDefault);

            if (! empty($result['unknown_department'])) {
                $key = mb_strtoupper($result['unknown_department']);
                $unknownDepartments[$key] = ($unknownDepartments[$key] ?? 0) + 1;
            }

            $category = $built['category'];
            $outcome = $category === 'error' ? ExcelRowOutcome::ERROR : ($built['warnings'] === [] ? ExcelRowOutcome::VALID : ExcelRowOutcome::WARNING);
            $counts[$category]++;
            if ($outcome === ExcelRowOutcome::WARNING) {
                $counts['warning']++;
            } elseif ($outcome === ExcelRowOutcome::VALID) {
                $validRows++;
            }

            $sheet = $entry['sheet'];
            $sheetCounts[$sheet] ??= ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0, 'warning' => 0];
            $sheetCounts[$sheet][$category]++;
            if ($outcome === ExcelRowOutcome::WARNING) {
                $sheetCounts[$sheet]['warning']++;
            }

            $rowsToInsert[] = [
                'import_batch_id' => $batch->id,
                // Sheets restart their row numbers, so this is the position
                // in the whole file; the sheet and its own row are in raw_row.
                'row_number' => $index + 1,
                'raw_row' => json_encode(['sheet' => $sheet, 'sheet_row' => $entry['sheet_row'], 'data' => $entry['data']]),
                'entity_type' => $student ? Student::class : null,
                'entity_id' => $student?->id,
                'confirm_context' => json_encode($built['context']),
                'outcome' => $outcome->value,
                'category' => $category,
                'messages' => json_encode(array_values([...$built['errors'], ...$built['warnings']])),
                'changes' => json_encode($built['changes']),
                'applied' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($batch, $rowsToInsert, $parsed, $sheetCounts, $sheetNotes, $counts, $validRows, $unknownDepartments) {
            $batch->rows()->delete();

            foreach (array_chunk($rowsToInsert, self::CHUNK) as $chunk) {
                ExcelImportRow::insert($chunk);
            }

            $sheets = array_map(fn ($sheet) => $sheet
                + ($sheetCounts[$sheet['name']] ?? ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0, 'warning' => 0])
                + ['note' => $sheetNotes[$sheet['name']] ?? null], $parsed['sheets']);
            $confirmable = $counts['new'] + $counts['updated'];

            $batch->update([
                'status' => $confirmable > 0 ? ExcelImportBatchStatus::READY_FOR_CONFIRMATION : ExcelImportBatchStatus::VALIDATED,
                'total_rows' => count($rowsToInsert),
                'matched_rows' => $counts['updated'] + $counts['unchanged'],
                'valid_rows' => $validRows,
                'warning_rows' => $counts['warning'],
                'error_rows' => $counts['error'],
                'error_message' => null,
                'summary' => array_merge($batch->summary ?? [], [
                    'counts' => $counts,
                    'sheets' => $sheets,
                    'unknown_departments' => collect($unknownDepartments)->map(fn ($count, $value) => ['value' => $value, 'rows' => $count])->values()->all(),
                ]),
            ]);
        });
    }

    /**
     * When most of a sheet's CGPA values are above 10, the column holds
     * percentages, not CGPAs (the college's 2024 sheet does this). That is
     * one sheet-level problem, not a warning on every row: the per-row
     * warnings are dropped and the sheet gets a single note. The CGPAs are
     * still left empty - never guessed from a percentage.
     *
     * @param  list<array{0: array, 1: array}>  $cleaned  rows, modified in place
     * @return array<string, string> sheet name => note
     */
    private function collapseSheetWideCgpaProblems(array &$cleaned): array
    {
        $isOutOfRange = fn (string $warning) => str_starts_with($warning, 'CGPA ') && str_contains($warning, 'is outside 0-10');
        $perSheet = [];

        foreach ($cleaned as [$entry, $result]) {
            $raw = $entry['data']['cgpa'] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $perSheet[$entry['sheet']]['filled'] = ($perSheet[$entry['sheet']]['filled'] ?? 0) + 1;
            if (collect($result['warnings'])->contains($isOutOfRange)) {
                $perSheet[$entry['sheet']]['out'] = ($perSheet[$entry['sheet']]['out'] ?? 0) + 1;
            }
        }

        $notes = [];
        foreach ($perSheet as $sheet => $tally) {
            $out = $tally['out'] ?? 0;
            if ($out >= 10 && $out / $tally['filled'] >= 0.5) {
                $notes[$sheet] = "The CGPA column looks like percentages ({$out} of {$tally['filled']} values are above 10), so those CGPAs were left empty. Send real CGPAs later as a Results update.";
            }
        }

        if ($notes !== []) {
            foreach ($cleaned as $i => [$entry, $result]) {
                if (isset($notes[$entry['sheet']])) {
                    $cleaned[$i][1]['warnings'] = array_values(array_filter($result['warnings'], fn ($w) => ! $isOutOfRange($w)));
                }
            }
        }

        return $notes;
    }

    /**
     * @param  list<string>  $rolls
     * @return Collection<string, Student>
     */
    private function existingStudents(array $rolls): Collection
    {
        $found = collect();

        foreach (array_chunk(array_unique($rolls), 1000) as $chunk) {
            $found = $found->merge(
                Student::query()->with(['user', 'department', 'batch'])->whereIn('roll_number', $chunk)->get()
                    ->keyBy(fn (Student $s) => mb_strtoupper($s->roll_number)),
            );
        }

        return $found;
    }

    private function buildStudentRow(array $result, ?Student $student): array
    {
        $values = $result['values'];

        if ($result['skip']) {
            return ['category' => 'error', 'errors' => $result['errors'], 'warnings' => $result['warnings'], 'changes' => [], 'context' => ['values' => $values, 'unknown_department' => $result['unknown_department']]];
        }

        $values['year'] = $this->calendar->currentYear($values['year_of_joining']);
        $values['batch_start'] = $values['year_of_joining'];
        $values['batch_end'] = $values['year_of_joining'] === null ? null : $values['year_of_joining'] + AcademicCalendar::PROGRAM_YEARS;
        $values['batch'] = $values['year_of_joining'] === null ? null : "{$values['batch_start']}-{$values['batch_end']}";

        $context = ['values' => $values, 'needs_one_time_password' => $result['needs_one_time_password']];

        if ($student === null) {
            return ['category' => 'new', 'errors' => [], 'warnings' => $result['warnings'], 'changes' => [], 'context' => $context];
        }

        // Existing student: an unclaimed account with no usable mobile keeps
        // whatever first password it already has - no new one-time password.
        $context['needs_one_time_password'] = false;
        $changes = $this->diff($student, $values);

        // One-time passwords are only for new accounts; for an existing
        // student a missing or bad mobile just keeps the number on record.
        $warnings = [];
        foreach ($result['warnings'] as $warning) {
            if (str_starts_with($warning, 'No mobile number')) {
                continue;
            }
            $warnings[] = str_replace('left empty and a one-time password will be generated', 'the current number is kept', $warning);
        }

        return [
            'category' => $changes === [] ? 'unchanged' : 'updated',
            'errors' => [],
            'warnings' => $warnings,
            'changes' => $changes,
            'context' => $context,
        ];
    }

    /**
     * Only fields with a value in the file can change - a blank never erases.
     *
     * @return list<array{field: string, label: string, old_value: mixed, new_value: mixed}>
     */
    private function diff(Student $student, array $values): array
    {
        $current = [
            'name' => $student->user?->name,
            'year_of_joining' => $student->year_of_joining,
            'gender' => $student->gender?->value,
            'department_id' => $student->department_id,
            'section' => $student->section,
            'phone' => $student->phone,
            'cgpa' => $student->cgpa === null ? null : (float) $student->cgpa,
            'backlogs' => $student->backlogs,
            'ssc_score' => $student->ssc_score === null ? null : (float) $student->ssc_score,
            'ssc_scale' => $student->ssc_scale?->value,
            'inter_score' => $student->inter_score === null ? null : (float) $student->inter_score,
            'inter_type' => $student->inter_type?->value,
            'entry_type' => $student->entry_type?->value,
            'program' => $student->program?->value,
            'batch_id' => $student->batch ? "{$student->batch->start_year}-{$student->batch->end_year}" : null,
            'year' => $student->year,
        ];
        $incoming = $values;
        $incoming['batch_id'] = $values['batch'];

        $display = [
            'department_id' => fn ($v, $isNew) => $isNew ? $values['department'] : $student->department?->name,
        ];

        $changes = [];
        foreach (self::FIELDS as $field => $label) {
            $new = $incoming[$field] ?? null;
            $old = $current[$field];

            if ($new === null || self::same($old, $new)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'old_value' => isset($display[$field]) ? $display[$field]($old, false) : $old,
                'new_value' => isset($display[$field]) ? $display[$field]($new, true) : $new,
            ];
        }

        return $changes;
    }

    private function buildAcademicRow(array $result, ?Student $student, ?string $semesterDefault): array
    {
        $values = $result['values'];
        $values['semester'] ??= $semesterDefault;
        $warnings = $result['warnings'];

        if ($result['skip']) {
            return ['category' => 'error', 'errors' => $result['errors'], 'warnings' => $warnings, 'changes' => [], 'context' => ['values' => $values]];
        }

        if ($student === null) {
            return ['category' => 'error', 'errors' => ["No student with JNTU No {$values['roll_number']} - import the full student list first."], 'warnings' => $warnings, 'changes' => [], 'context' => ['values' => $values]];
        }

        if ($values['cgpa'] === null && $values['backlogs'] === null && $warnings === []) {
            $warnings[] = 'CGPA and backlogs are both blank - nothing to update.';
        }

        $oldCgpa = $student->cgpa === null ? null : (float) $student->cgpa;
        $oldBacklogs = $student->backlogs;
        $changes = [];
        $flag = false;

        if ($values['cgpa'] !== null && ! self::same($oldCgpa, $values['cgpa'])) {
            $changes[] = ['field' => 'cgpa', 'label' => 'CGPA', 'old_value' => $oldCgpa, 'new_value' => $values['cgpa']];
            if ($oldCgpa !== null && abs($values['cgpa'] - $oldCgpa) > self::BIG_CGPA_CHANGE) {
                $flag = true;
                $warnings[] = sprintf('Big CGPA change: %.2f → %.2f.', $oldCgpa, $values['cgpa']);
            }
        }

        if ($values['backlogs'] !== null && $values['backlogs'] !== $oldBacklogs) {
            $changes[] = ['field' => 'backlogs', 'label' => 'Backlogs', 'old_value' => $oldBacklogs, 'new_value' => $values['backlogs']];
            if ($oldBacklogs !== null && $oldBacklogs - $values['backlogs'] > self::BIG_BACKLOG_DROP) {
                $flag = true;
                $warnings[] = sprintf('%d backlogs cleared at once (%d → %d).', $oldBacklogs - $values['backlogs'], $oldBacklogs, $values['backlogs']);
            }
        }

        $values['name'] = $student->user?->name;

        return [
            'category' => $changes === [] ? 'unchanged' : 'updated',
            'errors' => [],
            'warnings' => $warnings,
            'changes' => $changes,
            'context' => ['values' => $values, 'flag' => $flag],
        ];
    }

    private static function same(mixed $old, mixed $new): bool
    {
        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) < 0.005;
        }

        return (string) $old === (string) $new;
    }

    // =========================================================================
    // CONFIRM (queued) / CANCEL
    // =========================================================================

    public function confirm(ExcelImportBatch $batch, User $actor): ExcelImportBatch
    {
        if ($batch->status !== ExcelImportBatchStatus::READY_FOR_CONFIRMATION) {
            throw new ExcelImportException('This import is not ready to confirm.');
        }

        $total = $batch->rows()->whereIn('category', ['new', 'updated'])->count();

        $batch->update([
            'status' => ExcelImportBatchStatus::PROCESSING,
            'progress_total' => $total,
            'progress_done' => 0,
            'confirmed_by' => $actor->id,
        ]);

        ConfirmStudentImport::dispatch($batch->id, $actor->id);

        return $batch->fresh();
    }

    /**
     * Runs inside the queued job. Rows are applied in chunks of 500, each
     * chunk in its own transaction; progress is saved after every chunk.
     */
    public function process(ExcelImportBatch $batch, User $actor): void
    {
        if ($batch->status !== ExcelImportBatchStatus::PROCESSING) {
            return;
        }

        $isStudents = $batch->import_type === ExcelImportType::STUDENTS;
        $studentRoleId = Role::where('name', RoleName::STUDENT->value)->value('id');
        $oneTimePasswords = [];
        $applied = ['new' => 0, 'updated' => 0];
        $failed = 0;
        $done = 0;

        try {
            $batch->rows()->whereIn('category', ['new', 'updated'])->orderBy('row_number')
                ->chunkById(self::CHUNK, function ($rows) use ($batch, $actor, $isStudents, $studentRoleId, &$oneTimePasswords, &$applied, &$failed, &$done) {
                    DB::transaction(function () use ($rows, $batch, $actor, $isStudents, $studentRoleId, &$oneTimePasswords, &$applied, &$failed) {
                        foreach ($rows as $row) {
                            try {
                                if ($isStudents && $row->category === 'new') {
                                    $otp = $this->createStudent($row, $batch, $actor, $studentRoleId);
                                    if ($otp !== null) {
                                        $oneTimePasswords[] = $otp;
                                    }
                                } elseif ($isStudents) {
                                    $this->updateStudent($row, $batch, $actor);
                                } else {
                                    $this->applyAcademicUpdate($row, $batch, $actor);
                                }

                                $row->update(['applied' => true, 'applied_at' => now()]);
                                $applied[$row->category]++;
                            } catch (ExcelImportException $e) {
                                $row->update(['applied' => false, 'apply_error' => $e->getMessage()]);
                                $failed++;
                            }
                        }
                    });

                    $done += $rows->count();
                    $batch->update(['progress_done' => $done]);
                });
        } catch (Throwable $e) {
            report($e);
            $batch->update([
                'status' => ExcelImportBatchStatus::FAILED,
                'error_message' => 'The import stopped part-way because of an unexpected error. Rows saved before the error are kept; roll back this import or re-upload the file.',
                'secret' => $oneTimePasswords === [] ? null : $oneTimePasswords,
            ]);

            throw $e;
        }

        Storage::disk(self::DISK)->delete($batch->storage_path);

        $summary = $batch->summary ?? [];
        $summary['result'] = ['created' => $applied['new'], 'updated' => $applied['updated'], 'failed' => $failed, 'one_time_passwords' => count($oneTimePasswords)];

        $batch->update([
            'status' => ExcelImportBatchStatus::CONFIRMED,
            'confirmed_by' => $actor->id,
            'confirmed_at' => now(),
            'summary' => $summary,
            'secret' => $oneTimePasswords === [] ? null : $oneTimePasswords,
        ]);

        $this->audit->record('student.import_confirmed', "Confirmed {$batch->import_type->label()} import", $batch, $summary['result'], $actor);
    }

    public function cancel(ExcelImportBatch $batch, User $actor): ExcelImportBatch
    {
        if (! in_array($batch->status, [ExcelImportBatchStatus::READY_FOR_CONFIRMATION, ExcelImportBatchStatus::VALIDATED], true)) {
            throw new ExcelImportException('Only an import that has not been confirmed can be cancelled.');
        }

        Storage::disk(self::DISK)->delete($batch->storage_path);
        $batch->update(['status' => ExcelImportBatchStatus::CANCELLED]);
        $this->audit->record('student.import_cancelled', "Cancelled {$batch->import_type->label()} import", $batch, $this->countsFor($batch), $actor);

        return $batch->fresh();
    }

    /** @return ?array{roll_number: string, name: string, password: string} the one-time password, when one was needed */
    private function createStudent(ExcelImportRow $row, ExcelImportBatch $batch, User $actor, int $studentRoleId): ?array
    {
        $values = $row->confirm_context['values'];

        if (Student::query()->where('roll_number', $values['roll_number'])->exists()) {
            throw new ExcelImportException("A student with JNTU No {$values['roll_number']} was added after this preview; re-upload the file to update them.");
        }

        $batchModel = $values['year_of_joining'] ? $this->calendar->batchFor($values['year_of_joining']) : null;

        if ($batchModel === null) {
            throw new ExcelImportException('No year of joining, so no batch could be assigned.');
        }

        $oneTimePassword = $values['phone'] === null ? Str::password(10, symbols: false) : null;

        $user = new User([
            'name' => $values['name'],
            'email' => null,
            'role_id' => $studentRoleId,
            'is_active' => true,
        ]);
        // Mobile number (or a one-time password) as the first password - only ever stored hashed.
        $user->password = PasswordService::hashFirstPassword($oneTimePassword ?? $values['phone']);
        $user->forceFill(['must_change_password' => true, 'credentials_issued_at' => now()])->save();

        $student = Student::create([
            'user_id' => $user->id,
            'roll_number' => $values['roll_number'],
            'department_id' => $values['department_id'],
            'batch_id' => $batchModel->id,
            'year' => $values['year'],
            'section' => $values['section'],
            'gender' => $values['gender'],
            'year_of_joining' => $values['year_of_joining'],
            'entry_type' => $values['entry_type'],
            'program' => $values['program'],
            'phone' => $values['phone'],
            'cgpa' => $values['cgpa'],
            'backlogs' => $values['backlogs'],
            'ssc_score' => $values['ssc_score'],
            'ssc_scale' => $values['ssc_scale'],
            'inter_score' => $values['inter_score'],
            'inter_type' => $values['inter_type'],
            'academics_updated_at' => ($values['cgpa'] !== null || $values['backlogs'] !== null) ? now() : null,
        ]);

        $this->academicRecords->recordInitial($student, $batch->id, $actor, $batch->summary['semester_label'] ?? null);

        $row->update(['entity_type' => Student::class, 'entity_id' => $student->id]);
        $this->recordChange($batch, $row, Student::class, $student->id, '__created', null, $values['roll_number'], $actor, isCreation: true);

        return $oneTimePassword === null ? null : ['roll_number' => $values['roll_number'], 'name' => $values['name'], 'password' => $oneTimePassword];
    }

    private function updateStudent(ExcelImportRow $row, ExcelImportBatch $batch, User $actor): void
    {
        $student = Student::with(['user', 'batch'])->find($row->entity_id);

        if ($student === null) {
            throw new ExcelImportException('This student no longer exists.');
        }

        $values = $row->confirm_context['values'];
        $studentUpdates = [];
        $academic = ['cgpa' => null, 'backlogs' => null];

        foreach ($row->changes as $change) {
            $field = $change['field'];

            if ($field === 'name') {
                $old = $student->user->name;
                $student->user->update(['name' => $values['name']]);
                $this->recordChange($batch, $row, User::class, $student->user_id, 'name', $old, $values['name'], $actor);

                continue;
            }

            if ($field === 'cgpa' || $field === 'backlogs') {
                $academic[$field] = $values[$field];
                $old = $field === 'cgpa' ? ($student->cgpa === null ? null : (float) $student->cgpa) : $student->backlogs;
                $this->recordChange($batch, $row, Student::class, $student->id, $field, $old, $values[$field], $actor);

                continue;
            }

            if ($field === 'batch_id') {
                $newBatch = $this->calendar->batchFor($values['year_of_joining']);
                $this->recordChange($batch, $row, Student::class, $student->id, 'batch_id', $student->batch_id, $newBatch->id, $actor);
                $studentUpdates['batch_id'] = $newBatch->id;

                continue;
            }

            $old = $student->{$field} instanceof \BackedEnum ? $student->{$field}->value : $student->{$field};
            $this->recordChange($batch, $row, Student::class, $student->id, $field, $old, $values[$field], $actor);
            $studentUpdates[$field] = $values[$field];
        }

        if ($studentUpdates !== []) {
            $student->update($studentUpdates);
        }

        // A new mobile number for a student who hasn't signed in yet also
        // becomes their first password. A password they set is never touched.
        if (isset($studentUpdates['phone']) && $student->user && $student->user->must_change_password && $student->user->password_changed_at === null) {
            $student->user->forceFill(['password' => PasswordService::hashFirstPassword($studentUpdates['phone'])])->save();
        }

        if ($academic['cgpa'] !== null || $academic['backlogs'] !== null) {
            $this->academicRecords->apply(
                $student->fresh(), $academic['cgpa'], $academic['backlogs'], AcademicRecordSource::IMPORT, $actor,
                'Student list import', $batch->summary['semester_label'] ?? null, $batch->id,
            );
        }
    }

    private function applyAcademicUpdate(ExcelImportRow $row, ExcelImportBatch $batch, User $actor): void
    {
        $student = Student::with('user')->find($row->entity_id);

        if ($student === null) {
            throw new ExcelImportException('This student no longer exists.');
        }

        $values = $row->confirm_context['values'];
        $oldCgpa = $student->cgpa === null ? null : (float) $student->cgpa;
        $oldBacklogs = $student->backlogs;

        $record = $this->academicRecords->apply(
            $student, $values['cgpa'], $values['backlogs'], AcademicRecordSource::IMPORT, $actor,
            'Results update', $values['semester'] ?? null, $batch->id,
        );

        if ($record === null) {
            return;
        }

        if ($values['cgpa'] !== null && ! self::same($oldCgpa, $values['cgpa'])) {
            $this->recordChange($batch, $row, Student::class, $student->id, 'cgpa', $oldCgpa, $values['cgpa'], $actor);
        }
        if ($values['backlogs'] !== null && $values['backlogs'] !== $oldBacklogs) {
            $this->recordChange($batch, $row, Student::class, $student->id, 'backlogs', $oldBacklogs, $values['backlogs'], $actor);
        }
    }

    private function recordChange(ExcelImportBatch $batch, ExcelImportRow $row, string $entityType, int $entityId, string $field, mixed $old, mixed $new, User $actor, bool $isCreation = false): void
    {
        ExcelImportChange::create([
            'import_batch_id' => $batch->id,
            'excel_import_row_id' => $row->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'old_value' => $old === null ? null : (string) $old,
            'new_value' => $new === null ? null : (string) $new,
            'changed_by' => $actor->id,
            'is_creation' => $isCreation,
        ]);
    }

    // =========================================================================
    // ROLLBACK
    // =========================================================================

    /**
     * Why this import can't be rolled back, or null when it can. A student
     * import can only be undone until one of the students it created signs
     * in or applies.
     */
    public function rollbackBlocker(ExcelImportBatch $batch): ?string
    {
        if ($batch->status !== ExcelImportBatchStatus::CONFIRMED) {
            return 'Only a confirmed import can be rolled back.';
        }

        if ($batch->import_type !== ExcelImportType::STUDENTS) {
            return null;
        }

        $createdIds = $batch->changes()->where('is_creation', true)->whereNull('rolled_back_at')->pluck('entity_id');
        $active = Student::query()->whereIn('id', $createdIds)
            ->where(fn ($q) => $q
                ->whereHas('user', fn ($u) => $u->whereNotNull('last_login_at')->orWhereNotNull('password_changed_at'))
                ->orWhereHas('applications'))
            ->count();

        return $active > 0
            ? "{$active} student(s) from this import have already signed in or applied, so it can no longer be rolled back."
            : null;
    }

    /**
     * @return array{removed: int, reverted: int, conflicts: int}
     */
    public function rollback(ExcelImportBatch $batch, User $actor): array
    {
        if ($blocker = $this->rollbackBlocker($batch)) {
            throw new ExcelImportException($blocker);
        }

        $removed = 0;
        $reverted = 0;
        $conflicts = 0;

        DB::transaction(function () use ($batch, $actor, &$removed, &$reverted, &$conflicts) {
            $changes = $batch->changes()->whereNull('rolled_back_at')->whereNull('is_rollback_of')->orderByDesc('id')->get();
            $academicReverts = [];

            foreach ($changes as $change) {
                if ($change->is_creation) {
                    $student = Student::find($change->entity_id);
                    if ($student) {
                        User::whereKey($student->user_id)->delete(); // cascades to the student and their history
                        $removed++;
                    }
                    $change->update(['rolled_back_at' => now()]);

                    continue;
                }

                $model = $change->entity_type === User::class ? User::find($change->entity_id) : Student::find($change->entity_id);
                if ($model === null) {
                    $conflicts++;

                    continue;
                }

                $current = $model->{$change->field};
                $current = $current instanceof \BackedEnum ? $current->value : $current;
                if (! self::same($current, $change->new_value)) {
                    $conflicts++;

                    continue;
                }

                if (in_array($change->field, ['cgpa', 'backlogs'], true)) {
                    $academicReverts[$change->entity_id][$change->field] = $change->old_value;
                } else {
                    $model->forceFill([$change->field => $change->old_value])->save();
                }

                $change->update(['rolled_back_at' => now()]);
                $reverted++;
            }

            foreach ($academicReverts as $studentId => $fields) {
                $student = Student::find($studentId);
                $previousCgpa = $student->cgpa === null ? null : (float) $student->cgpa;
                $previousBacklogs = $student->backlogs;
                $student->forceFill([
                    'cgpa' => array_key_exists('cgpa', $fields) ? $fields['cgpa'] : $student->cgpa,
                    'backlogs' => array_key_exists('backlogs', $fields) ? $fields['backlogs'] : $student->backlogs,
                    'academics_updated_at' => now(),
                ])->save();

                StudentAcademicRecord::create([
                    'student_id' => $student->id,
                    'cgpa' => $student->cgpa,
                    'backlogs' => $student->backlogs,
                    'previous_cgpa' => $previousCgpa,
                    'previous_backlogs' => $previousBacklogs,
                    'source' => AcademicRecordSource::IMPORT,
                    'import_id' => $batch->id,
                    'changed_by' => $actor->id,
                    'reason' => "Rolled back import #{$batch->id}",
                ]);
            }

            $batch->update([
                'status' => ExcelImportBatchStatus::ROLLED_BACK,
                'rolled_back_by' => $actor->id,
                'rolled_back_at' => now(),
                'secret' => null,
            ]);
        });

        $result = ['removed' => $removed, 'reverted' => $reverted, 'conflicts' => $conflicts];
        $this->audit->record('student.import_rolled_back', "Rolled back {$batch->import_type->label()} import", $batch, $result, $actor);

        return $result;
    }

    // =========================================================================
    // one-time passwords, issues download
    // =========================================================================

    /**
     * The one-time passwords generated for students without a valid mobile.
     * Returned once, then wiped.
     *
     * @return list<array{roll_number: string, name: string, password: string}>
     */
    public function takeOneTimePasswords(ExcelImportBatch $batch): array
    {
        $passwords = $batch->secret ?? [];

        if ($passwords !== []) {
            $batch->forceFill(['secret' => null])->save();
            $summary = $batch->summary ?? [];
            $summary['one_time_passwords_viewed_at'] = now()->toIso8601String();
            $batch->update(['summary' => $summary]);
        }

        return $passwords;
    }

    public function issuesSpreadsheet(ExcelImportBatch $batch): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Issues');
        $sheet->fromArray(['Sheet', 'Row', 'JNTU No', 'Name', 'Type', 'Problem'], null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $line = 2;
        $batch->rows()->whereIn('outcome', [ExcelRowOutcome::ERROR->value, ExcelRowOutcome::WARNING->value])->orderBy('row_number')
            ->chunk(self::CHUNK, function ($rows) use ($sheet, &$line) {
                foreach ($rows as $row) {
                    $raw = $row->raw_row;
                    $values = $row->confirm_context['values'] ?? [];
                    foreach ($row->messages as $message) {
                        $sheet->fromArray([
                            $raw['sheet'] ?? '',
                            $raw['sheet_row'] ?? '',
                            $values['roll_number'] ?: (string) ($raw['data']['roll_number'] ?? ''),
                            $values['name'] ?? (string) ($raw['data']['name'] ?? ''),
                            $row->outcome === ExcelRowOutcome::ERROR ? 'Error (row skipped)' : 'Warning',
                            $message,
                        ], null, "A{$line}", true);
                        $line++;
                    }
                }
            });

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /** Counts only - audit entries never carry student data. */
    private function countsFor(ExcelImportBatch $batch): array
    {
        return ($batch->fresh()->summary['counts'] ?? []) + ['rows' => $batch->total_rows];
    }
}
