<?php

namespace Tests\Feature\Faculty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/faculty/reports/summary - see Api\Faculty\ReportController.
 * Always scoped to the authenticated faculty member's own Faculty
 * record (no id parameter exists on this route) - mirrors
 * Tests\Feature\Student\ReportTest's own IDOR check.
 */
class ReportTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
    }

    public function test_a_faculty_member_sees_their_own_workload(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $response = $this->getJson('/api/faculty/reports/summary')->assertOk();

        $this->assertSame($fixture['faculty']->id, $response->json('data.workload.faculty_id'));
        $this->assertSame(1, $response->json('data.workload.assigned_students'));
        $this->assertSame(1, $response->json('data.workload.active_students'));
    }

    public function test_a_faculty_member_with_no_assignments_sees_zeroed_workload(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $response = $this->getJson('/api/faculty/reports/summary')->assertOk();

        $this->assertSame(0, $response->json('data.workload.assigned_students'));
        $this->assertSame(0, $response->json('data.workload.active_students'));
    }

    public function test_a_faculty_members_workload_never_includes_another_facultys_students(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();
        $otherFaculty = $this->makeFaculty();

        Sanctum::actingAs($otherFaculty->user, ['*']);
        $response = $this->getJson('/api/faculty/reports/summary')->assertOk();

        $this->assertSame(0, $response->json('data.workload.assigned_students'));
    }

    public function test_a_student_cannot_use_the_faculty_report_route(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson('/api/faculty/reports/summary')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/faculty/reports/summary')->assertUnauthorized();
    }
}
