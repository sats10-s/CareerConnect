<?php

namespace Tests\Feature\Admin;

use App\Models\StudentPlacementStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

class StudentPlacementStatusTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacementFixtures();
    }

    public function test_authorized_admin_can_set_placement_details_and_audit_actor_and_time(): void
    {
        $studentUser = $this->makeStudentUser(['placement_status' => 'UNKNOWN']);
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", [
            'placement_status' => 'PLACED',
            'placement_company' => 'Example Corp',
            'placement_job_role' => 'Software Engineer',
            'placement_package' => 15.25,
            'placement_date' => now()->subDay()->toDateString(),
            'reason' => 'Offer letter verified.',
        ])->assertOk();

        $student = $studentUser->student->fresh();
        $this->assertSame('PLACED', $student->placement_status);
        $this->assertSame('Example Corp', $student->placement_company);
        $this->assertSame('Software Engineer', $student->placement_job_role);
        $this->assertSame('15.25', $student->placement_package);
        $this->assertSame($admin->id, $student->placement_status_updated_by);
        $this->assertNotNull($student->placement_status_updated_at);
        $history = StudentPlacementStatusHistory::where('student_id', $student->id)->sole();
        $this->assertSame('UNKNOWN', $history->from_status);
        $this->assertSame('PLACED', $history->to_status);
        $this->assertSame($admin->id, $history->changed_by);
        $this->assertSame('Offer letter verified.', $history->reason);
    }

    public function test_students_and_faculty_cannot_change_placement_status(): void
    {
        $studentUser = $this->makeStudentUser(['placement_status' => 'UNKNOWN']);
        Sanctum::actingAs($studentUser, ['*']);
        $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", ['placement_status' => 'PLACED'])->assertForbidden();

        $faculty = $this->makeFaculty();
        Sanctum::actingAs($faculty->user, ['*']);
        $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", ['placement_status' => 'PLACED'])->assertForbidden();

        $this->assertSame('UNKNOWN', $studentUser->student->fresh()->placement_status);
        $this->assertDatabaseCount('student_placement_status_history', 0);
    }

    public function test_not_placed_clears_current_offer_details_and_preserves_the_old_details_in_history(): void
    {
        $studentUser = $this->makeStudentUser(['placement_status' => 'PLACED', 'placement_company' => 'Old Corp', 'placement_job_role' => 'Analyst', 'placement_package' => 10]);
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", ['placement_status' => 'NOT_PLACED'])->assertOk();
        $student = $studentUser->student->fresh();
        $this->assertSame('NOT_PLACED', $student->placement_status);
        $this->assertNull($student->placement_company);
        $history = StudentPlacementStatusHistory::where('student_id', $student->id)->sole();
        $this->assertSame('Old Corp', $history->old_details['placement_company']);
    }

    public function test_invalid_package_and_future_placement_date_are_rejected(): void
    {
        $studentUser = $this->makeStudentUser(['placement_status' => 'UNKNOWN']);
        Sanctum::actingAs($this->makeAdmin(), ['*']);

        $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", [
            'placement_status' => 'PLACED', 'placement_package' => -1, 'placement_date' => now()->addDay()->toDateString(),
        ])->assertUnprocessable();
        $this->assertSame('UNKNOWN', $studentUser->student->fresh()->placement_status);
    }

    public function test_placement_history_can_be_appended_but_model_updates_are_rejected(): void
    {
        $studentUser = $this->makeStudentUser(['placement_status' => 'UNKNOWN']);
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", ['placement_status' => 'PLACED'])->assertOk();

        $history = StudentPlacementStatusHistory::where('student_id', $studentUser->student->id)->sole();
        $this->expectException(\LogicException::class);
        $history->update(['reason' => 'mutated']);
    }

    public function test_placement_history_model_deletion_is_rejected(): void
    {
        $studentUser = $this->makeStudentUser(['placement_status' => 'UNKNOWN']);
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", ['placement_status' => 'PLACED'])->assertOk();

        $history = StudentPlacementStatusHistory::where('student_id', $studentUser->student->id)->sole();
        $this->expectException(\LogicException::class);
        $history->delete();
    }

    public function test_placement_history_bulk_mutations_are_rejected(): void
    {
        $studentUser = $this->makeStudentUser(['placement_status' => 'UNKNOWN']);
        Sanctum::actingAs($this->makeAdmin(), ['*']);
        $this->patchJson("/api/admin/placement-eligibility/students/{$studentUser->student->id}", ['placement_status' => 'PLACED'])->assertOk();

        try {
            StudentPlacementStatusHistory::where('student_id', $studentUser->student->id)->update(['reason' => 'mutated']);
            $this->fail('Bulk history updates should be rejected.');
        } catch (\LogicException) {
            // Expected: the model's append-only query builder rejects bulk writes.
        }

        try {
            StudentPlacementStatusHistory::where('student_id', $studentUser->student->id)->delete();
            $this->fail('Bulk history deletes should be rejected.');
        } catch (\LogicException) {
            // Expected: the model's append-only query builder rejects bulk deletes.
        }

        $this->assertDatabaseCount('student_placement_status_history', 1);
    }
}
