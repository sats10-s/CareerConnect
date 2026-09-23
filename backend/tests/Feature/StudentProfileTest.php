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
 * their own personal/academic information and professional links. See
 * StudentProfileController + Http\Requests\Student\UpdateProfileRequest.
 *
 * Skills/projects/certifications/experiences/resume each have their own
 * dedicated test file (StudentSkillTest, StudentProjectTest, etc.) - see
 * those for the IDOR/ownership coverage on those sub-resources.
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
        $response->assertJsonPath('data.college_email', 'aarav@careerconnect.edu');
        $response->assertJsonPath('data.roll_number', 'CC24001');
        $response->assertJsonPath('data.department', 'Computer Science & Engineering');
        $response->assertJsonPath('data.batch', '2022-2026');
        $response->assertJsonPath('data.year', 2);
        $response->assertJsonPath('data.cgpa', 8.25);
        $response->assertJsonPath('data.backlogs', 0);
        $response->assertJsonPath('data.skills', []);
        $response->assertJsonPath('data.projects', []);
        $response->assertJsonPath('data.certifications', []);
        $response->assertJsonPath('data.experiences', []);
        $response->assertJsonPath('data.resume', null);
        $response->assertJsonStructure(['data' => ['profile_completion']]);
    }

    public function test_student_can_update_personal_academic_and_link_fields(): void
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
            'personal_email' => 'aarav.personal@gmail.com',
            'linkedin_url' => 'https://linkedin.com/in/aarav',
            'github_url' => 'https://github.com/aarav',
            'portfolio_url' => 'https://aarav.dev',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.department', 'Information Technology');
        $response->assertJsonPath('data.batch', '2023-2027');
        $response->assertJsonPath('data.year', 3);
        $response->assertJsonPath('data.cgpa', 9.1);
        $response->assertJsonPath('data.backlogs', 1);
        $response->assertJsonPath('data.phone', '9999999999');
        $response->assertJsonPath('data.personal_email', 'aarav.personal@gmail.com');
        $response->assertJsonPath('data.linkedin_url', 'https://linkedin.com/in/aarav');
        $response->assertJsonPath('data.github_url', 'https://github.com/aarav');
        $response->assertJsonPath('data.portfolio_url', 'https://aarav.dev');

        $this->assertDatabaseHas('students', [
            'user_id' => $user->id,
            'department_id' => $this->otherDepartment->id,
            'batch_id' => $this->otherBatch->id,
            'year' => 3,
            'backlogs' => 1,
            'personal_email' => 'aarav.personal@gmail.com',
        ]);
    }

    public function test_college_email_cannot_be_changed_via_profile_update(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'college_email' => 'attacker@evil.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['college_email']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'aarav@careerconnect.edu']);

        // Same guard on the legacy key name.
        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'email' => 'attacker@evil.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'aarav@careerconnect.edu']);
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
            'personal_email' => 'not-an-email',
            'linkedin_url' => 'not-a-url',
            'github_url' => 'not-a-url',
            'portfolio_url' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'roll_number', 'department_id', 'batch_id', 'year', 'cgpa', 'backlogs',
            'personal_email', 'linkedin_url', 'github_url', 'portfolio_url',
        ]);
    }

    public function test_cgpa_must_be_within_zero_to_ten(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'cgpa' => 10.01,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cgpa']);

        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'cgpa' => -0.5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cgpa']);
    }

    public function test_backlogs_cannot_be_negative(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'backlogs' => -1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['backlogs']);
    }

    public function test_year_must_be_between_one_and_four(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        foreach ([0, 5] as $invalidYear) {
            $response = $this->putJson('/api/student/profile', [
                'roll_number' => 'CC24001',
                'department_id' => $this->department->id,
                'batch_id' => $this->batch->id,
                'year' => $invalidYear,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['year']);
        }
    }

    public function test_profile_update_allows_keeping_own_unchanged_roll_number(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

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

    /**
     * A student cannot read or write another student's profile - there's
     * no id in the URL to manipulate (the route always resolves to
     * $request->user()->student), so this proves that by construction:
     * two different authenticated students each only ever see their own
     * data from the exact same endpoint.
     */
    public function test_student_cannot_view_or_modify_another_students_profile_data(): void
    {
        $studentA = $this->makeStudent(); // CC24001 / aarav@careerconnect.edu
        $studentB = $this->makeStudent([
            'email' => 'diya@careerconnect.edu',
            'roll_number' => 'CC24002',
            'phone' => '9000000001',
        ]);

        Sanctum::actingAs($studentB, ['*']);

        $response = $this->getJson('/api/student/profile');
        $response->assertOk();
        $response->assertJsonPath('data.college_email', 'diya@careerconnect.edu');
        $response->assertJsonPath('data.roll_number', 'CC24002');

        // Student B "updating their profile" can never touch student A's
        // row - update() is always scoped to $request->user()->student.
        $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24002',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 1,
            'phone' => '9111111111',
        ])->assertOk();

        $this->assertDatabaseHas('students', ['user_id' => $studentA->id, 'phone' => '9876543210']);
        $this->assertDatabaseHas('students', ['user_id' => $studentB->id, 'phone' => '9111111111']);
    }

    /**
     * Verifies the deterministic, weighted completion calculation end to
     * end via the real API rather than asserting on the service class in
     * isolation - each section's weight only appears once its rule is
     * satisfied, and never partially.
     */
    public function test_profile_completion_reflects_weighted_sections(): void
    {
        // Minimal student: name + college email exist (from makeStudent),
        // but no personal_email -> personal (15%) incomplete. Academic
        // fields are all present (roll_number/department/batch/year/cgpa/
        // backlogs) -> academic (15%) complete. Nothing else filled in.
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/student/profile');
        $response->assertJsonPath('data.profile_completion', 15); // academic only

        // Add personal_email + phone (phone already set by makeStudent) -> personal complete too.
        $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'personal_email' => 'aarav.personal@gmail.com',
        ])->assertOk();

        $response = $this->getJson('/api/student/profile');
        $response->assertJsonPath('data.profile_completion', 30); // personal + academic

        // A single skill earns the full 10% skills weight.
        $this->postJson('/api/student/profile/skills', ['skill_name' => 'Laravel'])->assertOk();
        $this->getJson('/api/student/profile')->assertJsonPath('data.profile_completion', 40);

        // Only two of three links -> the 15% links weight does NOT apply yet.
        $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'personal_email' => 'aarav.personal@gmail.com',
            'linkedin_url' => 'https://linkedin.com/in/aarav',
            'github_url' => 'https://github.com/aarav',
        ])->assertOk();
        $this->getJson('/api/student/profile')->assertJsonPath('data.profile_completion', 40);

        // Adding the third link completes the set -> +15%.
        $this->putJson('/api/student/profile', [
            'roll_number' => 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
            'personal_email' => 'aarav.personal@gmail.com',
            'linkedin_url' => 'https://linkedin.com/in/aarav',
            'github_url' => 'https://github.com/aarav',
            'portfolio_url' => 'https://aarav.dev',
        ])->assertOk();
        $this->getJson('/api/student/profile')->assertJsonPath('data.profile_completion', 55);
    }
}
