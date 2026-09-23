<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exercises GET/PUT /api/student/profile: a student viewing and editing
 * their own roll_number/department/batch/year/cgpa/backlogs/phone. See
 * StudentProfileController + Http\Requests\Student\UpdateProfileRequest.
 */
class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private Role $studentRole;

    private Role $facultyRole;

    private Department $department;

    private Department $otherDepartment;

    private Batch $batch;

    private Batch $otherBatch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->studentRole = Role::where('name', RoleName::STUDENT->value)->firstOrFail();
        $this->facultyRole = Role::where('name', RoleName::FACULTY->value)->firstOrFail();

        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->otherDepartment = Department::create(['name' => 'Information Technology', 'code' => 'IT']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
        $this->otherBatch = Batch::create(['name' => '2023-2027', 'start_year' => 2023, 'end_year' => 2027]);
    }

    private function makeStudent(array $overrides = []): User
    {
        $user = User::create([
            'name' => 'Aarav Mehta',
            'email' => $overrides['email'] ?? 'aarav@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);

        Student::create(array_merge([
            'user_id' => $user->id,
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'cgpa' => 8.25,
            'backlogs' => 0,
            'phone' => '9876543210',
        ], $overrides));

        return $user;
    }

    public function test_student_can_view_own_profile(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/student/profile');

        $response->assertOk();
        $response->assertJsonPath('data.student.roll_number', 'CC24001');
        $response->assertJsonPath('data.student.department', 'Computer Science & Engineering');
        $response->assertJsonPath('data.student.batch', '2022-2026');
        $response->assertJsonPath('data.student.year', 2);
        $response->assertJsonPath('data.student.cgpa', 8.25);
        $response->assertJsonPath('data.student.backlogs', 0);
    }

    public function test_student_can_update_own_profile(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->otherDepartment->id,
            'batch_id' => $this->otherBatch->id,
            'year' => 3,
            'cgpa' => 9.1,
            'backlogs' => 1,
            'phone' => '9999999999',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.student.department', 'Information Technology');
        $response->assertJsonPath('data.student.batch', '2023-2027');
        $response->assertJsonPath('data.student.year', 3);
        $response->assertJsonPath('data.student.cgpa', 9.1);
        $response->assertJsonPath('data.student.backlogs', 1);
        $response->assertJsonPath('data.student.phone', '9999999999');

        $this->assertDatabaseHas('students', [
            'user_id' => $user->id,
            'department_id' => $this->otherDepartment->id,
            'batch_id' => $this->otherBatch->id,
            'year' => 3,
            'backlogs' => 1,
        ]);
    }

    public function test_profile_update_rejects_invalid_payload(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/student/profile', [
            'roll_number' => '',
            'department_id' => 999999,
            'batch_id' => 999999,
            'year' => 9,
            'cgpa' => 11,
            'backlogs' => -1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['roll_number', 'department_id', 'batch_id', 'year', 'cgpa', 'backlogs']);
    }

    public function test_profile_update_allows_keeping_own_unchanged_roll_number(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        // Re-submitting the same roll_number the student already has must
        // not trip the uniqueness rule against themselves.
        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
        ]);

        $response->assertOk();
    }

    public function test_profile_update_rejects_roll_number_already_used_by_another_student(): void
    {
        $this->makeStudent(); // CC24001 / aarav@careerconnect.edu

        $otherUser = $this->makeStudent([
            'email' => 'diya@careerconnect.edu',
            'roll_number' => 'CC24002',
        ]);
        Sanctum::actingAs($otherUser, ['*']);

        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['roll_number']);
    }

    public function test_guest_cannot_view_or_update_student_profile(): void
    {
        $this->getJson('/api/student/profile')->assertStatus(401);
        $this->putJson('/api/student/profile', [])->assertStatus(401);
    }

    public function test_non_student_cannot_access_student_profile(): void
    {
        $facultyUser = User::create([
            'name' => 'Dr. Priya Sharma',
            'email' => 'priya@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->facultyRole->id,
        ]);
        Sanctum::actingAs($facultyUser, ['*']);

        $this->getJson('/api/student/profile')->assertStatus(403);
        $this->putJson('/api/student/profile', [])->assertStatus(403);
    }
}
