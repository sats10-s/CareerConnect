<?php

namespace Tests\Feature\SuperAdmin;

use App\Mail\WeeklyHodSummary;
use App\Models\Department;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

class WeeklyHodSummaryTest extends TestCase
{
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
    }

    public function test_super_admin_sets_and_clears_a_department_hod_email(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);

        $this->patchJson("/api/super-admin/departments/{$this->excelDepartment->id}", ['hod_email' => 'hod.cse@college.edu'])
            ->assertOk()->assertJsonPath('data.hod_email', 'hod.cse@college.edu');
        $this->patchJson("/api/super-admin/departments/{$this->excelDepartment->id}", ['hod_email' => 'not-an-email'])
            ->assertUnprocessable()->assertJsonValidationErrors('hod_email');
        $this->patchJson("/api/super-admin/departments/{$this->excelDepartment->id}", ['hod_email' => null])
            ->assertOk()->assertJsonPath('data.hod_email', null);
    }

    public function test_nothing_is_sent_while_the_setting_is_off(): void
    {
        Mail::fake();
        $this->excelDepartment->update(['hod_email' => 'hod.cse@college.edu']);

        $this->artisan('reports:weekly-hod-summary')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_each_hod_gets_their_own_department_summary_with_both_excel_files(): void
    {
        Mail::fake();
        app(SettingsService::class)->update(['weekly_hod_summary' => true], null);
        $this->excelDepartment->update(['hod_email' => 'hod.cse@college.edu']);
        $ece = Department::create(['name' => 'Electronics', 'code' => 'ECE']);
        $noHod = Department::create(['name' => 'Civil', 'code' => 'CIVIL']);

        $admin = $this->makeAdmin();
        $placed = $this->makeStudentUser(['section' => 'A']);
        $this->makeStudentUser(['section' => 'A']);
        $this->makeStudentUser(['department_id' => $noHod->id]);
        $this->applyAndAdvance($admin, $placed, $this->makeJobPosting(), ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']);

        $this->artisan('reports:weekly-hod-summary')->assertSuccessful();

        Mail::assertSent(WeeklyHodSummary::class, 1);
        Mail::assertSent(WeeklyHodSummary::class, function (WeeklyHodSummary $mail) {
            $classes = collect($mail->classes);

            return $mail->hasTo('hod.cse@college.edu')
                && $mail->department->is($this->excelDepartment)
                && $classes->sum('total_students') === 2
                && $classes->sum('students_placed') === 1
                && $mail->newSelections === 1
                && count($mail->attachments()) === 2
                && str_contains($mail->render(), '1 of 2 students placed');
        });
        $this->assertNull($ece->hod_email);
    }

    public function test_weekly_summary_setting_round_trips(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin(), ['*']);

        $this->patchJson('/api/super-admin/settings', ['weekly_hod_summary' => true])->assertOk();
        $this->assertTrue(app(SettingsService::class)->get('weekly_hod_summary'));
    }
}
