<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Attendance;
use App\Models\InternshipAssignment;
use App\Models\Interview;
use App\Models\PpoRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * Interview Schedule, Internship Status (assignment + PPO), and
 * Attendance imports - module spec sections 14-16 - each confirmed
 * through the SAME domain service a manual admin/faculty action would
 * use (InterviewService / InternshipAssignmentService /
 * InternshipCompletionService::updatePpo / AttendanceService), never a
 * raw DB write.
 */
class ExcelImportInterviewInternshipAttendanceTest extends TestCase
{
    use BuildsExcelFixtures;
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
        Storage::fake('local');
        $this->admin = $this->makeAdmin();
    }

    private function upload(string $type, array $headers, array $rows): array
    {
        Sanctum::actingAs($this->admin, ['*']);
        $file = $this->makeXlsxUpload($headers, $rows);

        return $this->postJson('/api/admin/excel/import', ['type' => $type, 'file' => $file])
            ->assertCreated()->json('data');
    }

    private function confirm(int $batchId): array
    {
        Sanctum::actingAs($this->admin, ['*']);

        return $this->postJson("/api/admin/excel/imports/{$batchId}/confirm")->assertOk()->json('data');
    }

    // --- Interview Schedule ---------------------------------------------------

    public function test_scheduling_a_new_interview_advances_the_application_via_the_real_service(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC25001']);
        $job = $this->makeJobPosting();
        $applicationId = $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW', 'SHORTLISTED']);

        $batch = $this->upload('INTERVIEW_SCHEDULE',
            ['Student Name', 'Roll Number', 'Company', 'Job Role', 'Status', 'Interview Date', 'Interview Time', 'Mode', 'Venue', 'Meeting Link'],
            [['Asha Rao', 'CC25001', $this->excelCompany->name, $job->title, 'SCHEDULED', '2026-10-12', '10:30', 'ONLINE', null, 'https://meet.example.com/xyz']],
        );

        $this->assertSame(1, $batch['counts']['valid_rows']);

        $result = $this->confirm($batch['id']);
        $this->assertSame(1, $result['result']['applied']);

        $interview = Interview::where('application_id', $applicationId)->first();
        $this->assertNotNull($interview);
        $this->assertSame('SCHEDULED', $interview->status->value);
        $this->assertSame(1, $interview->round);
        $this->assertSame('ONLINE', $interview->mode->value);

        // InterviewService::create() auto-advances SHORTLISTED -> INTERVIEW.
        $this->assertSame('INTERVIEW', Application::findOrFail($applicationId)->status->value);
    }

    public function test_rescheduling_an_existing_interview_updates_it_in_place_not_a_second_row(): void
    {
        $studentUser = $this->makeStudentUser(['name' => 'Asha Rao']);
        $studentUser->student->update(['roll_number' => 'CC25002']);
        $job = $this->makeJobPosting();
        $applicationId = $this->applyAndAdvance($this->admin, $studentUser, $job, ['UNDER_REVIEW', 'SHORTLISTED']);

        $headers = ['Student Name', 'Roll Number', 'Company', 'Job Role', 'Status', 'Interview Date', 'Interview Time', 'Mode', 'Venue', 'Meeting Link'];

        $firstBatch = $this->upload('INTERVIEW_SCHEDULE', $headers, [
            ['Asha Rao', 'CC25002', $this->excelCompany->name, $job->title, 'SCHEDULED', '2026-10-12', '10:30', 'ONLINE', null, 'https://meet.example.com/xyz'],
        ]);
        $this->confirm($firstBatch['id']);

        $secondBatch = $this->upload('INTERVIEW_SCHEDULE', $headers, [
            ['Asha Rao', 'CC25002', $this->excelCompany->name, $job->title, 'RESCHEDULED', '2026-10-20', '14:00', 'ONLINE', null, 'https://meet.example.com/xyz'],
        ]);
        $this->assertSame(1, $secondBatch['counts']['valid_rows']);
        $this->confirm($secondBatch['id']);

        $this->assertSame(1, Interview::where('application_id', $applicationId)->count());
        $interview = Interview::where('application_id', $applicationId)->first();
        $this->assertSame('RESCHEDULED', $interview->status->value);
        $this->assertSame(20, $interview->scheduled_at->day);
    }

    // --- Internship Status (assignment + PPO) ----------------------------------

    public function test_completing_an_internship_assignment_via_excel(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture($this->admin);
        $fixture['student']->student->update(['roll_number' => 'CC25003']);

        $batch = $this->upload('INTERNSHIP_STATUS',
            ['Student Name', 'Roll Number', 'Company', 'Internship Role', 'Start Date', 'End Date', 'Status', 'PPO Status'],
            [[$fixture['student']->name, 'CC25003', $this->excelCompany->name, $fixture['internship']->title, null, null, 'COMPLETED', null]],
        );

        $this->assertSame(1, $batch['counts']['valid_rows']);
        $this->confirm($batch['id']);

        $this->assertSame('COMPLETED', InternshipAssignment::findOrFail($fixture['assignment_id'])->status->value);
    }

    public function test_ppo_status_column_updates_an_existing_ppo_record_via_the_completion_service(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture($this->admin);
        $fixture['student']->student->update(['roll_number' => 'CC25004']);

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $ppoId = $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/ppo", [
            'offered_at' => now()->toDateString(),
            'designation' => 'Software Engineer',
            'package' => 1200000,
        ])->assertCreated()->json('data.id');

        $batch = $this->upload('INTERNSHIP_STATUS',
            ['Student Name', 'Roll Number', 'Company', 'Internship Role', 'Start Date', 'End Date', 'Status', 'PPO Status'],
            [[$fixture['student']->name, 'CC25004', $this->excelCompany->name, $fixture['internship']->title, null, null, null, 'ACCEPTED']],
        );

        $this->assertSame(1, $batch['counts']['valid_rows']);
        $this->confirm($batch['id']);

        $this->assertSame('ACCEPTED', PpoRecord::findOrFail($ppoId)->status->value);
    }

    public function test_ppo_status_column_is_ignored_with_a_message_when_no_ppo_record_exists(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture($this->admin);
        $fixture['student']->student->update(['roll_number' => 'CC25005']);

        $batch = $this->upload('INTERNSHIP_STATUS',
            ['Student Name', 'Roll Number', 'Company', 'Internship Role', 'Start Date', 'End Date', 'Status', 'PPO Status'],
            [[$fixture['student']->name, 'CC25005', $this->excelCompany->name, $fixture['internship']->title, null, null, null, 'ACCEPTED']],
        );

        // No PPO record exists yet, so nothing is created (module spec:
        // "PPO is optional" / never auto-created by this import) - the
        // row is a WARNING with an explanatory message, not an ERROR.
        $this->assertSame(1, $batch['counts']['warning_rows']);
        $this->assertDatabaseCount('ppo_records', 0);
    }

    // --- Attendance --------------------------------------------------------------

    public function test_marking_attendance_via_excel_creates_a_real_attendance_record(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture($this->admin);
        $fixture['student']->student->update(['roll_number' => 'CC25006']);

        $batch = $this->upload('ATTENDANCE',
            ['Roll Number', 'Student Name', 'Internship', 'Attendance Date', 'Status', 'Check In', 'Check Out'],
            [['CC25006', $fixture['student']->name, $fixture['internship']->title, now()->toDateString(), 'PRESENT', '09:30', '18:00']],
        );

        $this->assertSame(1, $batch['counts']['valid_rows']);
        $this->confirm($batch['id']);

        $attendance = Attendance::where('internship_assignment_id', $fixture['assignment_id'])->first();
        $this->assertNotNull($attendance);
        $this->assertSame('PRESENT', $attendance->status->value);
    }

    public function test_a_duplicate_attendance_date_is_rejected(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture($this->admin);
        $fixture['student']->student->update(['roll_number' => 'CC25007']);
        $date = now()->toDateString();

        $headers = ['Roll Number', 'Student Name', 'Internship', 'Attendance Date', 'Status', 'Check In', 'Check Out'];
        $firstBatch = $this->upload('ATTENDANCE', $headers, [
            ['CC25007', $fixture['student']->name, $fixture['internship']->title, $date, 'PRESENT', '09:30', '18:00'],
        ]);
        $this->confirm($firstBatch['id']);

        $secondBatch = $this->upload('ATTENDANCE', $headers, [
            ['CC25007', $fixture['student']->name, $fixture['internship']->title, $date, 'ABSENT', null, null],
        ]);

        $this->assertSame(1, $secondBatch['counts']['error_rows']);
        $this->assertSame(1, Attendance::where('internship_assignment_id', $fixture['assignment_id'])->count());
    }
}
