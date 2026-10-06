<?php

namespace Tests\Feature;

use App\Enums\AcademicRecordSource;
use App\Enums\RoleName;
use App\Models\AppSetting;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Student\AcademicCalendar;
use App\Services\Student\AcademicRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The college record on the student's own profile: every one of the 11
 * columns of the college's file comes back, plus what is derived from them.
 */
class StudentCollegeRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $department = Department::create(['name' => 'Artificial Intelligence & Machine Learning', 'code' => 'AIML']);
        $batch = Batch::create(['name' => '2023-2027', 'start_year' => 2023, 'end_year' => 2027]);

        $this->user = User::create([
            'name' => 'Test Student',
            'email' => null,
            'password' => 'Password@123',
            'role_id' => Role::where('name', RoleName::STUDENT->value)->value('id'),
        ]);

        Student::create([
            'user_id' => $this->user->id,
            'roll_number' => '24345A6601',
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'year' => 4,
            'section' => 'B',
            'gender' => 'female',
            'year_of_joining' => 2023,
            'entry_type' => 'lateral',
            'program' => 'btech',
            'phone' => '9000000001',
            'cgpa' => 8.05,
            'backlogs' => null,
            'ssc_score' => 9.8,
            'ssc_scale' => 'cgpa10',
            'inter_score' => 91.5,
            'inter_type' => 'diploma',
        ]);
    }

    public function test_profile_returns_all_eleven_college_fields_and_derived_values(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->getJson('/api/student/profile')->assertOk()->assertJson(['data' => [
            'name' => 'Test Student',
            'roll_number' => '24345A6601',
            'year_of_joining' => 2023,
            'gender' => 'female',
            'department' => 'Artificial Intelligence & Machine Learning',
            'section' => 'B',
            'phone' => '9000000001',
            'cgpa' => 8.05,
            'backlogs' => null,
            'ssc_score' => 9.8,
            'ssc_scale' => 'cgpa10',
            'inter_score' => 91.5,
            'inter_type' => 'diploma',
            'batch' => '2023-2027',
            'batch_start_year' => 2023,
            'batch_end_year' => 2027,
            'year' => 4,
            'entry_type' => 'lateral',
            'program' => 'btech',
            'college_email' => null,
        ]]);
    }

    public function test_academic_history_records_old_and_new_values(): void
    {
        $student = $this->user->student;
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@x.test', 'password' => 'x', 'role_id' => Role::where('name', 'SUPER_ADMIN')->value('id')]);

        $service = app(AcademicRecordService::class);
        $this->assertNotNull($service->apply($student, 8.4, 0, AcademicRecordSource::MANUAL, $admin, 'Revaluation'));
        // Nothing changed -> no history row.
        $this->assertNull($service->apply($student->fresh(), 8.4, null, AcademicRecordSource::MANUAL, $admin, 'again'));

        $this->assertDatabaseHas('student_academic_records', [
            'student_id' => $student->id, 'cgpa' => 8.4, 'backlogs' => 0, 'previous_cgpa' => 8.05, 'previous_backlogs' => null,
            'source' => 'manual', 'changed_by' => $admin->id, 'reason' => 'Revaluation',
        ]);
        $this->assertDatabaseCount('student_academic_records', 1);
        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $this->user->id,
            'type' => 'ACADEMIC_RECORD_UPDATED',
            'message' => 'Your academic record was updated: CGPA 8.40, backlogs 0.',
        ]);

        Sanctum::actingAs($this->user, ['*']);
        $this->getJson('/api/student/profile')->assertOk()
            ->assertJsonPath('data.academic_history.0.cgpa', 8.4)
            ->assertJsonPath('data.academic_history.0.previous_cgpa', 8.05)
            // Students see the history, not the staff member or the reason.
            ->assertJsonPath('data.academic_history.0.changed_by', null);
    }

    public function test_current_year_follows_the_academic_year_setting(): void
    {
        AppSetting::create(['key' => 'academic_year', 'value' => '2026-27']);
        $calendar = app(AcademicCalendar::class);

        $this->assertSame(4, $calendar->currentYear(2023));
        $this->assertSame(3, $calendar->currentYear(2024));
        $this->assertSame(1, $calendar->currentYear(2026));
        // Clamped to the four B.Tech years.
        $this->assertSame(4, $calendar->currentYear(2020));
        $this->assertNull($calendar->currentYear(null));
        $this->assertSame('2024-2028', $calendar->batchFor(2024)->name);
    }
}
