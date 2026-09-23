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
 * POST /api/student/profile/skills and DELETE /api/student/profile/skills/{skill}.
 * See App\Http\Controllers\Api\Student\SkillController + StudentSkillPolicy.
 */
class StudentSkillTest extends TestCase
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

    public function test_student_can_add_a_skill(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/student/profile/skills', ['skill_name' => 'Laravel']);

        $response->assertOk();
        $response->assertJsonPath('data.skills.0.skill_name', 'Laravel');
        $this->assertDatabaseHas('student_skills', [
            'student_id' => $user->student->id,
            'skill_name' => 'Laravel',
        ]);
    }

    public function test_skill_name_is_required(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/skills', ['skill_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['skill_name']);
    }

    public function test_student_cannot_add_the_same_skill_twice(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/student/profile/skills', ['skill_name' => 'Laravel'])->assertOk();

        $response = $this->postJson('/api/student/profile/skills', ['skill_name' => 'Laravel']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['skill_name']);
    }

    public function test_student_can_delete_own_skill(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $skill = $user->student->skills()->create(['skill_name' => 'React']);

        $response = $this->deleteJson("/api/student/profile/skills/{$skill->id}");

        $response->assertOk();
        $response->assertJsonPath('data.skills', []);
        $this->assertDatabaseMissing('student_skills', ['id' => $skill->id]);
    }

    /**
     * IDOR: Student A must not be able to delete Student B's skill by
     * changing the {skill} id in the URL.
     */
    public function test_student_cannot_delete_another_students_skill(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent(['email' => 'diya@careerconnect.edu', 'roll_number' => 'CC24002']);

        $bsSkill = $studentB->student->skills()->create(['skill_name' => 'Python']);

        Sanctum::actingAs($studentA, ['*']);

        $response = $this->deleteJson("/api/student/profile/skills/{$bsSkill->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('student_skills', ['id' => $bsSkill->id, 'skill_name' => 'Python']);
    }

    public function test_deleting_a_nonexistent_skill_returns_not_found(): void
    {
        $user = $this->makeStudent();
        Sanctum::actingAs($user, ['*']);

        $this->deleteJson('/api/student/profile/skills/999999')->assertStatus(404);
    }

    public function test_guest_cannot_add_or_delete_skills(): void
    {
        $this->postJson('/api/student/profile/skills', ['skill_name' => 'Laravel'])->assertStatus(401);
        $this->deleteJson('/api/student/profile/skills/1')->assertStatus(401);
    }
}
