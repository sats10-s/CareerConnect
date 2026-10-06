<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/** "Update academics" from the Students drawer, and what a CGPA/backlog change does to eligibility. */
class StudentAcademicsUpdateTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacementFixtures();
    }

    public function test_admin_updates_academics_with_a_reason_and_the_student_is_notified(): void
    {
        $student = $this->makeStudentUser(['cgpa' => 7.8, 'backlogs' => 2]);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);
        $url = "/api/admin/students/{$student->student->id}/academics";

        $this->postJson($url, ['cgpa' => 8.05])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson($url, ['reason' => 'Revaluation'])->assertStatus(422)->assertJsonValidationErrors(['cgpa', 'backlogs']);
        $this->postJson($url, ['cgpa' => 10.5, 'reason' => 'Typo'])->assertStatus(422)->assertJsonValidationErrors(['cgpa']);

        $this->postJson($url, ['cgpa' => 8.05, 'backlogs' => 0, 'semester_label' => '3-2', 'reason' => 'Revaluation results'])
            ->assertOk()->assertJsonPath('data.cgpa', 8.05)->assertJsonPath('data.backlogs', 0)->assertJsonPath('data.changed', true);

        $this->assertDatabaseHas('student_academic_records', [
            'student_id' => $student->student->id, 'cgpa' => 8.05, 'backlogs' => 0, 'previous_cgpa' => 7.8, 'previous_backlogs' => 2,
            'source' => 'manual', 'changed_by' => $admin->id, 'reason' => 'Revaluation results', 'semester_label' => '3-2',
        ]);
        $this->assertDatabaseHas('notifications', [
            'recipient_user_id' => $student->id, 'type' => 'ACADEMIC_RECORD_UPDATED',
            'message' => 'Your academic record was updated: CGPA 8.05, backlogs 0.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.academics_updated', 'actor_id' => $admin->id]);

        // The drawer shows who changed what, and why.
        $this->getJson("/api/admin/students/{$student->student->id}")->assertOk()
            ->assertJsonPath('data.profile.academic_history.0.changed_by', $admin->name)
            ->assertJsonPath('data.profile.academic_history.0.reason', 'Revaluation results');

        // Same values again: no new history row.
        $this->postJson($url, ['cgpa' => 8.05, 'reason' => 'again'])->assertOk()->assertJsonPath('data.changed', false);
        $this->assertDatabaseCount('student_academic_records', 1);
    }

    public function test_only_the_placement_office_can_update_academics(): void
    {
        $student = $this->makeStudentUser();
        Sanctum::actingAs($student, ['*']);
        $this->postJson("/api/admin/students/{$student->student->id}/academics", ['cgpa' => 9.9, 'reason' => 'me'])->assertForbidden();

        Sanctum::actingAs($this->makeFaculty()->user, ['*']);
        $this->postJson("/api/admin/students/{$student->student->id}/academics", ['cgpa' => 9.9, 'reason' => 'me'])->assertForbidden();

        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);
        $this->postJson("/api/admin/students/{$student->student->id}/academics", ['cgpa' => 9.0, 'reason' => 'Exam cell correction'])->assertOk();
    }

    public function test_lower_cgpa_applies_to_eligibility_at_once_without_withdrawing_applications(): void
    {
        $job = $this->makeJobPosting();
        $job->syncEligibility(['eligibility_enforced' => true, 'min_cgpa' => 7.5]);
        $student = $this->makeStudentUser(['cgpa' => 8.0]);
        $admin = $this->makeAdmin();
        $applicationId = $this->applyAndAdvance($admin, $student, $job, ['UNDER_REVIEW']);

        Sanctum::actingAs($admin, ['*']);
        $row = fn () => collect($this->getJson('/api/admin/applications')->assertOk()->json('data.items'))->firstWhere('id', $applicationId);
        $this->assertSame(['meets' => true, 'reasons' => []], $row()['criteria']);

        $this->postJson("/api/admin/students/{$student->student->id}/academics", ['cgpa' => 7.0, 'reason' => 'Corrected by exam cell'])->assertOk();

        $this->assertSame('UNDER_REVIEW', $row()['status']);
        $this->assertSame(['meets' => false, 'reasons' => ['Minimum CGPA required is 7.50.']], $row()['criteria']);

        Sanctum::actingAs($student->fresh(), ['*']);
        $this->getJson("/api/student/opportunities/jobs/{$job->id}")->assertOk()->assertJsonPath('data.my_eligibility.eligible', false);
    }
}
