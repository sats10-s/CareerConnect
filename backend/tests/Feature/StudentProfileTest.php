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

    public function test_student_can_update_their_own_profile_fields(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->patchJson('/api/student/profile', [
            'personal_email' => 'aarav.personal@gmail.com',
            'address' => '12 Lake Road, Vizag',
            'linkedin_url' => 'https://linkedin.com/in/aarav',
            'github_url' => 'https://github.com/aarav',
            'portfolio_url' => 'https://aarav.dev',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.personal_email', 'aarav.personal@gmail.com');
        $response->assertJsonPath('data.address', '12 Lake Road, Vizag');
        $response->assertJsonPath('data.linkedin_url', 'https://linkedin.com/in/aarav');
        $response->assertJsonPath('data.github_url', 'https://github.com/aarav');
        $response->assertJsonPath('data.portfolio_url', 'https://aarav.dev');
        // College record untouched.
        $response->assertJsonPath('data.cgpa', 8.25);
        $response->assertJsonPath('data.department', 'Computer Science & Engineering');

        // PATCH semantics: sending one field leaves the others alone.
        $this->patchJson('/api/student/profile', ['address' => ''])->assertOk()
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.linkedin_url', 'https://linkedin.com/in/aarav');
    }

    public function test_any_college_record_field_from_a_student_is_rejected(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $collegeFields = [
            'name' => 'Someone Else', 'roll_number' => 'CC99999', 'year_of_joining' => 2020, 'gender' => 'male',
            'department_id' => $this->otherDepartment->id, 'section' => 'B', 'phone' => '9999999999',
            'cgpa' => 9.9, 'backlogs' => 0, 'ssc_score' => 99, 'inter_score' => 99,
            'batch_id' => $this->otherBatch->id, 'year' => 4, 'college_email' => 'x@evil.com', 'email' => 'x@evil.com',
        ];

        foreach ($collegeFields as $field => $value) {
            $this->patchJson('/api/student/profile', [$field => $value, 'address' => 'ok'])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseHas('students', ['user_id' => $user->id, 'cgpa' => 8.25, 'roll_number' => 'CC24001', 'address' => null]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'aarav@careerconnect.edu', 'name' => 'Aarav Mehta']);
    }

    public function test_profile_update_rejects_invalid_links_and_email(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/student/profile', [
            'personal_email' => 'not-an-email',
            'linkedin_url' => 'https://github.com/aarav',
            'github_url' => 'not-a-url',
            'portfolio_url' => 'http://aarav.dev',
            'address' => str_repeat('a', 501),
        ])->assertStatus(422)->assertJsonValidationErrors([
            'personal_email', 'linkedin_url', 'github_url', 'portfolio_url', 'address',
        ]);
    }

    public function test_linkedin_must_be_a_linkedin_profile_link(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        foreach (['https://www.linkedin.com/in/aarav-mehta', 'https://linkedin.com/in/aarav/', 'https://www.linkedin.com/in/aarav?trk=x'] as $ok) {
            $this->patchJson('/api/student/profile', ['linkedin_url' => $ok])->assertOk();
        }

        foreach (['http://www.linkedin.com/in/aarav', 'https://www.linkedin.com/company/acme', 'https://evil.com/linkedin.com/in/aarav', 'linkedin.com/in/aarav', 'https://aarav.dev'] as $bad) {
            $this->patchJson('/api/student/profile', ['linkedin_url' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors(['linkedin_url']);
        }
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
        $this->patchJson('/api/student/profile', ['address' => 'B street'])->assertOk();

        $this->assertDatabaseHas('students', ['user_id' => $studentA->id, 'address' => null]);
        $this->assertDatabaseHas('students', ['user_id' => $studentB->id, 'address' => 'B street']);
    }

    /**
     * Completion counts only student-fillable fields: LinkedIn 15,
     * portfolio 15, 3+ skills 20, personal email 15, address 10, resume 25.
     * College-record fields (all present here) count for nothing.
     */
    public function test_profile_completion_counts_only_student_fillable_fields(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/student/profile')->assertJsonPath('data.profile_completion', 0);

        $this->patchJson('/api/student/profile', ['personal_email' => 'aarav.personal@gmail.com'])->assertOk()
            ->assertJsonPath('data.profile_completion', 15);

        // Skills only count from the third one.
        $this->postJson('/api/student/profile/skills', ['skill_name' => 'Laravel'])->assertOk();
        $this->postJson('/api/student/profile/skills', ['skill_name' => 'React'])->assertOk()
            ->assertJsonPath('data.profile_completion', 15);
        $this->postJson('/api/student/profile/skills', ['skill_name' => 'SQL'])->assertOk()
            ->assertJsonPath('data.profile_completion', 35);

        $this->patchJson('/api/student/profile', [
            'linkedin_url' => 'https://linkedin.com/in/aarav',
            'portfolio_url' => 'https://aarav.dev',
            'address' => 'Vizag',
        ])->assertOk()->assertJsonPath('data.profile_completion', 75);

        // The dashboard uses the same calculator.
        $this->getJson('/api/student/dashboard')->assertOk()->assertJsonPath('stats.profile_completion', 75);
    }
}
