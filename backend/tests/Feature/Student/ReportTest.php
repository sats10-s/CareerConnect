<?php

namespace Tests\Feature\Student;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/student/reports/summary - see Api\Student\ReportController.
 * Always scoped to the authenticated student's own Student record
 * (no id parameter exists on this route at all) - the IDOR check
 * below (test_a_students_summary_never_includes_another_students_data)
 * is the module spec's own explicit "server-side role/IDOR-safe
 * authorization" requirement, verified directly rather than assumed.
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

    public function test_a_student_sees_their_own_application_counts(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        $this->applyAndAdvance($admin, $studentUser, $internship, ['UNDER_REVIEW']);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/reports/summary')->assertOk();

        $this->assertSame(1, $response->json('data.total_applications'));
        $this->assertSame(1, $response->json('data.applications_by_status.UNDER_REVIEW'));
        $this->assertSame(0, $response->json('data.applications_by_status.APPLIED'));
    }

    public function test_a_students_summary_never_includes_another_students_data(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $otherStudentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        $this->applyAndAdvance($admin, $otherStudentUser, $internship);

        Sanctum::actingAs($studentUser, ['*']);
        $response = $this->getJson('/api/student/reports/summary')->assertOk();

        $this->assertSame(0, $response->json('data.total_applications'));
    }

    public function test_a_faculty_member_cannot_use_the_student_report_route(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson('/api/student/reports/summary')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/student/reports/summary')->assertUnauthorized();
    }
}
