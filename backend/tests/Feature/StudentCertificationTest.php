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
 * POST/PUT/DELETE /api/student/profile/certifications[/{certification}].
 * See App\Http\Controllers\Api\Student\CertificationController + StudentCertificationPolicy.
 */
class StudentCertificationTest extends TestCase
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
            'name' => 'AWS Certified Cloud Practitioner',
            'issuing_organization' => 'Amazon Web Services',
            'issue_date' => '2025-03-15',
            'credential_url' => 'https://aws.amazon.com/verification/abc123',
        ], $overrides);
    }

    public function test_student_can_add_a_certification(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/student/profile/certifications', $this->validPayload());

        $response->assertOk();
        $response->assertJsonPath('data.certifications.0.name', 'AWS Certified Cloud Practitioner');
        $response->assertJsonPath('data.certifications.0.issue_date', '2025-03-15');
        $this->assertDatabaseHas('student_certifications', [
            'student_id' => $user->student->id,
            'name' => 'AWS Certified Cloud Practitioner',
        ]);
    }

    public function test_name_issuing_organization_and_issue_date_are_required(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/certifications', [
            'name' => '',
            'issuing_organization' => '',
            'issue_date' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'issuing_organization', 'issue_date']);
    }

    public function test_issue_date_cannot_be_in_the_future(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/certifications', $this->validPayload(['issue_date' => '2999-01-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['issue_date']);
    }

    public function test_credential_url_must_be_a_valid_url(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/certifications', $this->validPayload(['credential_url' => 'not-a-url']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credential_url']);
    }

    public function test_student_can_update_own_certification(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $certification = $user->student->certifications()->create($this->validPayload());

        $response = $this->putJson(
            "/api/student/profile/certifications/{$certification->id}",
            $this->validPayload(['name' => 'AWS Certified Solutions Architect'])
        );

        $response->assertOk();
        $response->assertJsonPath('data.certifications.0.name', 'AWS Certified Solutions Architect');
    }

    public function test_student_can_delete_own_certification(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $certification = $user->student->certifications()->create($this->validPayload());

        $this->deleteJson("/api/student/profile/certifications/{$certification->id}")->assertOk();
        $this->assertDatabaseMissing('student_certifications', ['id' => $certification->id]);
    }

    /**
     * IDOR: Student A must not be able to update or delete Student B's
     * certification by changing the {certification} id in the URL.
     */
    public function test_student_cannot_update_or_delete_another_students_certification(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent(['email' => 'diya@careerconnect.edu', 'roll_number' => 'CC24002']);

        $bsCertification = $studentB->student->certifications()->create($this->validPayload());

        Sanctum::actingAs($studentA, ['*']);

        $this->putJson(
            "/api/student/profile/certifications/{$bsCertification->id}",
            $this->validPayload(['name' => 'Hijacked'])
        )->assertStatus(403);

        $this->deleteJson("/api/student/profile/certifications/{$bsCertification->id}")->assertStatus(403);

        $this->assertDatabaseHas('student_certifications', ['id' => $bsCertification->id, 'name' => 'AWS Certified Cloud Practitioner']);
    }

    public function test_guest_cannot_manage_certifications(): void
    {
        $this->postJson('/api/student/profile/certifications', $this->validPayload())->assertStatus(401);
        $this->putJson('/api/student/profile/certifications/1', $this->validPayload())->assertStatus(401);
        $this->deleteJson('/api/student/profile/certifications/1')->assertStatus(401);
    }
}
