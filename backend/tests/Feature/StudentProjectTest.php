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
 * POST/PUT/DELETE /api/student/profile/projects[/{project}].
 * See App\Http\Controllers\Api\Student\ProjectController + StudentProjectPolicy.
 */
class StudentProjectTest extends TestCase
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
            'title' => 'Placement Portal',
            'description' => 'A portal connecting students with recruiters.',
            'technologies' => 'Laravel, React, MySQL',
            'project_url' => 'https://github.com/aarav/placement-portal',
        ], $overrides);
    }

    public function test_student_can_add_a_project(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/student/profile/projects', $this->validPayload());

        $response->assertOk();
        $response->assertJsonPath('data.projects.0.title', 'Placement Portal');
        $this->assertDatabaseHas('student_projects', [
            'student_id' => $user->student->id,
            'title' => 'Placement Portal',
        ]);
    }

    public function test_title_is_required(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/projects', $this->validPayload(['title' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_project_url_must_be_a_valid_url(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/projects', $this->validPayload(['project_url' => 'not-a-url']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_url']);
    }

    public function test_student_can_update_own_project(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $project = $user->student->projects()->create($this->validPayload());

        $response = $this->putJson(
            "/api/student/profile/projects/{$project->id}",
            $this->validPayload(['title' => 'Placement Portal v2'])
        );

        $response->assertOk();
        $response->assertJsonPath('data.projects.0.title', 'Placement Portal v2');
        $this->assertDatabaseHas('student_projects', ['id' => $project->id, 'title' => 'Placement Portal v2']);
    }

    public function test_student_can_delete_own_project(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $project = $user->student->projects()->create($this->validPayload());

        $response = $this->deleteJson("/api/student/profile/projects/{$project->id}");

        $response->assertOk();
        $response->assertJsonPath('data.projects', []);
        $this->assertDatabaseMissing('student_projects', ['id' => $project->id]);
    }

    /**
     * IDOR: Student A must not be able to update or delete Student B's
     * project by changing the {project} id in the URL.
     */
    public function test_student_cannot_update_or_delete_another_students_project(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent(['email' => 'diya@careerconnect.edu', 'roll_number' => 'CC24002']);

        $bsProject = $studentB->student->projects()->create($this->validPayload(['title' => "Diya's Project"]));

        Sanctum::actingAs($studentA, ['*']);

        $this->putJson("/api/student/profile/projects/{$bsProject->id}", $this->validPayload(['title' => 'Hijacked']))
            ->assertStatus(403);

        $this->deleteJson("/api/student/profile/projects/{$bsProject->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('student_projects', ['id' => $bsProject->id, 'title' => "Diya's Project"]);
    }

    public function test_guest_cannot_manage_projects(): void
    {
        $this->postJson('/api/student/profile/projects', $this->validPayload())->assertStatus(401);
        $this->putJson('/api/student/profile/projects/1', $this->validPayload())->assertStatus(401);
        $this->deleteJson('/api/student/profile/projects/1')->assertStatus(401);
    }
}
