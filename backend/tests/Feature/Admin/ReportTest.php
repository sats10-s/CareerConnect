<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * GET /api/admin/reports/* (mirrored at /api/super-admin/reports/*) -
 * see Api\Admin\ReportController and every App\Services\Reporting\*
 * service. Every fixture below goes through the real student/admin/
 * faculty HTTP endpoints (BuildsPlacementFixtures::applyAndAdvance()/
 * activatedInternshipAssignmentFixture()) rather than direct model
 * writes, so a report is verified against exactly the state a real
 * user action would produce - including the ApplicationStatusHistory
 * rows/Attendance rows/etc. these reports actually read.
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

    public function test_admin_can_view_the_reports_dashboard(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/reports/dashboard')->assertOk();

        $response->assertJsonStructure([
            'data' => ['placements', 'application_funnel', 'internships', 'recent_trends'],
        ]);
    }

    public function test_placements_summary_counts_students_not_applications(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();
        $jobPosting = $this->makeJobPosting();

        // The SAME student applies to two different opportunities -
        // this must count as 1 student, 2 applications.
        Sanctum::actingAs($studentUser, ['*']);
        $this->postJson("/api/student/opportunities/internships/{$internship->id}/apply")->assertCreated();
        $this->postJson("/api/student/opportunities/jobs/{$jobPosting->id}/apply")->assertCreated();

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/reports/placements')->assertOk();

        $summary = $response->json('data.summary');
        $this->assertSame(2, $summary['total_applications']);
        $this->assertSame(1, $summary['unique_applicants']);
        $this->assertSame(1, $summary['students_applied']);
        $this->assertSame(2.0, $summary['avg_applications_per_applicant']);
    }

    public function test_placements_breakdown_by_company_reflects_selected_students(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        $this->applyAndAdvance($admin, $studentUser, $internship, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/reports/placements?dimension=company')->assertOk();

        $rows = $response->json('data.breakdown.rows');
        $this->assertCount(1, $rows);
        $this->assertSame($this->excelCompany->name, $rows[0]['label']);
        $this->assertSame(1, $rows[0]['students_selected']);
        $this->assertSame(100.0, $rows[0]['selection_rate']);
    }

    public function test_students_shortlisted_is_historical_not_current_status(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        // This student passes THROUGH shortlisted on the way to
        // SELECTED - students_shortlisted must still count them, even
        // though their CURRENT status is no longer SHORTLISTED.
        $this->applyAndAdvance($admin, $studentUser, $internship, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']);

        Sanctum::actingAs($admin, ['*']);
        $summary = $this->getJson('/api/admin/reports/placements')->json('data.summary');

        $this->assertSame(1, $summary['students_shortlisted']);
        $this->assertSame(1, $summary['students_selected']);
    }

    public function test_application_funnel_reports_all_eight_statuses_even_at_zero(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $funnel = $this->getJson('/api/admin/reports/applications')->assertOk()->json('data.funnel');

        foreach (['APPLIED', 'UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'INTERVIEW', 'SELECTED', 'REJECTED', 'WITHDRAWN'] as $status) {
            $this->assertArrayHasKey($status, $funnel);
            $this->assertSame(0, $funnel[$status]['applications']);
        }
    }

    public function test_application_funnel_reflects_current_status_snapshot(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        $this->applyAndAdvance($admin, $studentUser, $internship, ['UNDER_REVIEW', 'SHORTLISTED']);

        Sanctum::actingAs($admin, ['*']);
        $funnel = $this->getJson('/api/admin/reports/applications')->json('data.funnel');

        // Current status is SHORTLISTED - the funnel is a snapshot, so
        // it shows 1 application there and 0 in every status the
        // application already passed through (APPLIED, UNDER_REVIEW).
        $this->assertSame(1, $funnel['SHORTLISTED']['applications']);
        $this->assertSame(0, $funnel['APPLIED']['applications']);
        $this->assertSame(0, $funnel['UNDER_REVIEW']['applications']);
    }

    public function test_internship_report_counts_assignments_and_completions(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $summary = $this->getJson('/api/admin/reports/internships')->assertOk()->json('data');

        $this->assertSame(1, $summary['total_assignments']);
        $this->assertSame(1, $summary['assignments_by_status']['ACTIVE']);
        $this->assertSame(0, $summary['completions_finalized']);
    }

    public function test_attendance_report_computes_percentage_and_below_threshold(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['faculty']->user, ['*']);
        $this->postJson("/api/faculty/internship-assignments/{$fixture['assignment_id']}/attendance", [
            'attendance_date' => now()->toDateString(),
            'status' => 'ABSENT',
        ])->assertCreated();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $response = $this->getJson('/api/admin/reports/attendance?threshold=50')->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['absent_days']);
        $this->assertSame(0.0, $items[0]['attendance_percentage']);

        $belowThreshold = $response->json('data.below_threshold.rows');
        $this->assertCount(1, $belowThreshold);
        $this->assertSame(50.0, $response->json('data.below_threshold.threshold'));
    }

    public function test_faculty_workload_report_counts_assigned_students_and_pending_reviews(): void
    {
        $fixture = $this->activatedInternshipAssignmentFixture();

        Sanctum::actingAs($fixture['student'], ['*']);
        $reportId = $this->postJson("/api/student/internship-assignments/{$fixture['assignment_id']}/weekly-reports", [
            'week_number' => 1,
            'week_start_date' => now()->subDays(6)->toDateString(),
            'week_end_date' => now()->toDateString(),
            'work_done' => 'Set up the project environment and read onboarding docs.',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/student/weekly-reports/{$reportId}/submit")->assertOk();

        Sanctum::actingAs($fixture['admin'], ['*']);
        $rows = $this->getJson('/api/admin/reports/faculty')->assertOk()->json('data.items');

        $this->assertCount(1, $rows);
        $this->assertSame($fixture['faculty']->id, $rows[0]['faculty_id']);
        $this->assertSame(1, $rows[0]['assigned_students']);
        $this->assertSame(1, $rows[0]['active_students']);
        $this->assertSame(1, $rows[0]['pending_weekly_reports']);
    }

    public function test_companies_report_breaks_down_placement_activity_by_company(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        $this->applyAndAdvance($admin, $studentUser, $internship);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/reports/companies')->assertOk();

        $this->assertSame(1, $response->json('data.companies_count'));
        $this->assertSame($this->excelCompany->name, $response->json('data.rows.0.label'));
    }

    public function test_trends_report_buckets_applications_by_granularity(): void
    {
        $admin = $this->makeAdmin();
        $studentUser = $this->makeStudentUser();
        $internship = $this->makeInternship();

        $this->applyAndAdvance($admin, $studentUser, $internship);

        Sanctum::actingAs($admin, ['*']);
        $response = $this->getJson('/api/admin/reports/trends?granularity=day')->assertOk();

        $this->assertSame('day', $response->json('data.granularity'));
        $points = $response->json('data.points');
        $this->assertCount(1, $points);
        $this->assertSame(1, $points[0]['applications']);
    }

    public function test_a_student_cannot_access_admin_reports(): void
    {
        $studentUser = $this->makeStudentUser();

        Sanctum::actingAs($studentUser, ['*']);
        $this->getJson('/api/admin/reports/dashboard')->assertForbidden();
    }

    public function test_a_faculty_member_cannot_access_admin_reports(): void
    {
        $faculty = $this->makeFaculty();

        Sanctum::actingAs($faculty->user, ['*']);
        $this->getJson('/api/admin/reports/dashboard')->assertForbidden();
    }

    public function test_a_placement_admin_cannot_access_the_super_admin_only_reports_prefix(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/super-admin/reports/dashboard')->assertForbidden();
    }

    public function test_a_super_admin_can_access_both_the_admin_and_super_admin_reports_prefixes(): void
    {
        $superAdmin = $this->makeSuperAdmin();

        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/admin/reports/dashboard')->assertOk();
        $this->getJson('/api/super-admin/reports/dashboard')->assertOk();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/reports/dashboard')->assertUnauthorized();
    }

    public function test_out_of_range_filters_are_rejected(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/admin/reports/attendance?threshold=150')->assertStatus(422);
        $this->getJson('/api/admin/reports/trends?granularity=hour')->assertStatus(422);
        $this->getJson('/api/admin/reports/placements?dimension=faculty')->assertStatus(422);
    }
}
