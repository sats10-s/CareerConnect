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
 * POST/PUT/DELETE /api/student/profile/experiences[/{experience}].
 * See App\Http\Controllers\Api\Student\ExperienceController + StudentExperiencePolicy.
 */
class StudentExperienceTest extends TestCase
{
    use RefreshDatabase;

    private Role $studentRole;

    private Department $department;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::create(['name' => $role->value, 'label' => $role->label()]);
        }

        $this->studentRole = Role::where('name', RoleName::STUDENT->value)->firstOrFail();
        $this->department = Department::create(['name' => 'Computer Science & Engineering', 'code' => 'CSE']);
        $this->batch = Batch::create(['name' => '2022-2026', 'start_year' => 2022, 'end_year' => 2026]);
    }

    private function makeStudent(array $overrides = []): User
    {
        $user = User::create([
            'name' => $overrides['name'] ?? 'Aarav Mehta',
            'email' => $overrides['email'] ?? 'aarav@careerconnect.edu',
            'password' => Hash::make('Password@123'),
            'role_id' => $this->studentRole->id,
        ]);

        Student::create(array_merge([
            'user_id' => $user->id,
            'roll_number' => $overrides['roll_number'] ?? 'CC24001',
            'department_id' => $this->department->id,
            'batch_id' => $this->batch->id,
            'year' => 2,
        ], $overrides));

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'organization' => 'Acme Corp',
            'role' => 'Software Engineering Intern',
            'start_date' => '2025-05-01',
            'end_date' => '2025-07-31',
            'description' => 'Built internal tooling.',
        ], $overrides);
    }

    public function test_student_can_add_an_experience(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/student/profile/experiences', $this->validPayload());

        $response->assertOk();
        $response->assertJsonPath('data.experiences.0.organization', 'Acme Corp');
        $response->assertJsonPath('data.experiences.0.start_date', '2025-05-01');
        $response->assertJsonPath('data.experiences.0.end_date', '2025-07-31');
        $this->assertDatabaseHas('student_experiences', [
            'student_id' => $user->student->id,
            'organization' => 'Acme Corp',
        ]);
    }

    public function test_ongoing_experience_can_omit_end_date(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/student/profile/experiences', $this->validPayload(['end_date' => null]));

        $response->assertOk();
        $response->assertJsonPath('data.experiences.0.end_date', null);
    }

    public function test_organization_role_and_start_date_are_required(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/experiences', [
            'organization' => '',
            'role' => '',
            'start_date' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['organization', 'role', 'start_date']);
    }

    public function test_end_date_cannot_be_before_start_date(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/experiences', $this->validPayload([
            'start_date' => '2025-07-01',
            'end_date' => '2025-06-01',
        ]))->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    public function test_student_can_update_own_experience(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $experience = $user->student->experiences()->create($this->validPayload());

        $response = $this->putJson(
            "/api/student/profile/experiences/{$experience->id}",
            $this->validPayload(['role' => 'Senior Software Engineering Intern'])
        );

        $response->assertOk();
        $response->assertJsonPath('data.experiences.0.role', 'Senior Software Engineering Intern');
    }

    public function test_student_can_delete_own_experience(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $experience = $user->student->experiences()->create($this->validPayload());

        $this->deleteJson("/api/student/profile/experiences/{$experience->id}")->assertOk();
        $this->assertDatabaseMissing('student_experiences', ['id' => $experience->id]);
    }

    /**
     * IDOR: Student A must not be able to update or delete Student B's
     * experience entry by changing the {experience} id in the URL.
     */
    public function test_student_cannot_update_or_delete_another_students_experience(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent(['email' => 'diya@careerconnect.edu', 'roll_number' => 'CC24002']);

        $bsExperience = $studentB->student->experiences()->create($this->validPayload(['organization' => "Diya's Company"]));

        Sanctum::actingAs($studentA, ['*']);

        $this->putJson(
            "/api/student/profile/experiences/{$bsExperience->id}",
            $this->validPayload(['organization' => 'Hijacked'])
        )->assertStatus(403);

        $this->deleteJson("/api/student/profile/experiences/{$bsExperience->id}")->assertStatus(403);

        $this->assertDatabaseHas('student_experiences', ['id' => $bsExperience->id, 'organization' => "Diya's Company"]);
    }

    public function test_guest_cannot_manage_experiences(): void
    {
        $this->postJson('/api/student/profile/experiences', $this->validPayload())->assertStatus(401);
        $this->putJson('/api/student/profile/experiences/1', $this->validPayload())->assertStatus(401);
        $this->deleteJson('/api/student/profile/experiences/1')->assertStatus(401);
    }
}
