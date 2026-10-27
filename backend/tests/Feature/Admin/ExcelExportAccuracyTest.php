<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Internship;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsExcelFixtures;
use Tests\Concerns\BuildsPlacementFixtures;
use Tests\TestCase;

/**
 * Every Excel Sync Center figure that counts students or applications,
 * checked against a direct query on the same data. The fixture mirrors
 * the college's Student Data import: two departments, the 2023-27
 * batch in year 3 and the 2024-28 batch in year 2 (no CGPA yet),
 * sections A and B (and some students with no section), and some
 * blank backlogs.
 */
class ExcelExportAccuracyTest extends TestCase
{
    use BuildsExcelFixtures;
    use BuildsPlacementFixtures;
    use RefreshDatabase;

    private User $admin;

    private Department $aiml;

    private Batch $batch2023;

    private Batch $batch2024;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPlacementFixtures();
        Storage::fake('local');
        $this->admin = $this->makeAdmin();
        $this->aiml = Department::create(['name' => 'Artificial Intelligence & Machine Learning', 'code' => 'AIML']);
        $this->batch2023 = Batch::create(['name' => '2023-27', 'start_year' => 2023, 'end_year' => 2027]);
        $this->batch2024 = Batch::create(['name' => '2024-28', 'start_year' => 2024, 'end_year' => 2028]);

        $this->seedCollege();
    }

    /**
     * 12 students across 2 departments x 2 years x 2 sections (some
     * classes deliberately empty), with a mix of applications.
     */
    private function seedCollege(): void
    {
        $job = $this->makeJobPosting();
        $otherJob = $this->makeJobPosting(['title' => 'Data Analyst']);
        $internship = $this->makeInternship();

        $students = [
            // dept, batch, year, section, cgpa, backlogs, applications [opportunity, statuses]
            ['cse', 2023, 3, 'A', 8.4, 0, [[$job, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']], [$internship, []]]],
            ['cse', 2023, 3, 'A', 7.1, 2, [[$job, ['UNDER_REVIEW', 'REJECTED']]]],
            ['cse', 2023, 3, 'A', 6.9, null, []],
            ['cse', 2023, 3, 'B', 9.0, 0, [[$otherJob, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']], [$job, ['WITHDRAWN']]]],
            ['cse', 2023, 3, 'B', 5.8, 4, [[$internship, ['UNDER_REVIEW']]]],
            ['cse', 2024, 2, 'A', null, null, [[$internship, []]]],
            ['cse', 2024, 2, 'A', null, 0, []],
            ['aiml', 2023, 3, 'A', 8.8, 0, [[$job, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']]]],
            ['aiml', 2023, 3, 'A', 7.5, 1, [[$otherJob, []], [$internship, ['UNDER_REVIEW', 'SHORTLISTED']]]],
            ['aiml', 2024, 2, 'B', null, null, [[$internship, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']]]],
            ['aiml', 2024, 2, 'B', null, null, []],
            ['aiml', 2024, 2, 'B', null, 0, [[$job, ['UNDER_REVIEW', 'REJECTED']]]],
            // Section is optional in the college file.
            ['aiml', 2024, 2, null, null, null, [[$job, []]]],
            ['cse', 2023, 3, null, 7.9, 0, [[$internship, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'SELECTED']]]],
        ];

        foreach ($students as $i => [$dept, $batch, $year, $section, $cgpa, $backlogs, $applications]) {
            $user = $this->makeStudentUser([
                'name' => 'Student '.$i,
                'roll_number' => sprintf('%dA91A%04d', $batch % 100, $i + 1),
                'department_id' => $dept === 'cse' ? $this->excelDepartment->id : $this->aiml->id,
                'batch_id' => $batch === 2023 ? $this->batch2023->id : $this->batch2024->id,
                'year' => $year,
                'section' => $section,
                'cgpa' => $cgpa,
                'backlogs' => $backlogs,
            ]);
            foreach ($applications as [$opportunity, $through]) {
                $withdraw = $through === ['WITHDRAWN'];
                $applicationId = $this->applyAndAdvance($this->admin, $user, $opportunity, $withdraw ? [] : $through);

                if ($withdraw) {
                    Sanctum::actingAs($user, ['*']);
                    $this->postJson("/api/student/applications/{$applicationId}/withdraw")->assertOk();
                }
            }

            // One placement is explicitly verified; other SELECTED
            // applications in this fixture remain application outcomes.
            if ($i === 0) {
                $user->student->update(['placement_status' => 'PLACED']);
            }
        }
    }

    /**
     * @return list<array<string, mixed>> one array per data row, keyed by header
     */
    private function exportRows(string $type, array $filters = [], ?string $sheetName = null): array
    {
        $sheet = $this->exportSheet($type, $filters, $sheetName);
        $rows = $sheet->toArray(null, true, false, false);
        $headers = array_shift($rows);

        return array_map(fn (array $row) => array_combine($headers, $row), $rows);
    }

    private function exportSheet(string $type, array $filters = [], ?string $sheetName = null): Worksheet
    {
        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->get('/api/admin/excel/exports/'.$type.'?'.http_build_query($filters))->assertOk();
        $spreadsheet = $this->spreadsheetFromResponseContent($response->streamedContent());

        return $sheetName ? $spreadsheet->getSheetByName($sheetName) : $spreadsheet->getActiveSheet();
    }

    private function studentIdsWhere(array $where): \Illuminate\Support\Collection
    {
        return Student::query()->where($where)->pluck('id');
    }

    public function test_students_export_lists_every_student_with_department_year_and_section(): void
    {
        $rows = $this->exportRows('STUDENTS');

        $this->assertCount(Student::count(), $rows);

        foreach (Student::with(['department', 'batch'])->get() as $student) {
            $row = collect($rows)->firstWhere('JNTU No', $student->roll_number);
            $this->assertNotNull($row, $student->roll_number);
            $this->assertSame($student->department->name, $row['Department']);
            $this->assertSame($student->batch->name, $row['Batch']);
            $this->assertEquals($student->year, $row['Year']);
            $this->assertSame($student->section, $row['Section']);
            $this->assertEquals($student->cgpa === null ? 'Not yet available' : (float) $student->cgpa, $row['CGPA']);
            $this->assertEquals($student->backlogs ?? 'Not reported', $row['Backlogs']);
        }
    }

    public function test_students_export_filters_by_department_year_and_section(): void
    {
        $cases = [
            ['department_id' => $this->aiml->id],
            ['year' => 2],
            ['section' => 'b'],
            ['department_id' => $this->excelDepartment->id, 'year' => 3, 'section' => 'A'],
            ['batch_id' => $this->batch2024->id, 'section' => 'B'],
        ];

        foreach ($cases as $filters) {
            $where = $filters;
            if (isset($where['section'])) {
                $where['section'] = strtoupper($where['section']);
            }

            $expected = Student::where($where)->pluck('roll_number')->sort()->values()->all();
            $actual = collect($this->exportRows('STUDENTS', $filters))->pluck('JNTU No')->sort()->values()->all();

            $this->assertSame($expected, $actual, json_encode($filters));
        }
    }

    public function test_applications_export_honours_student_filters_and_carries_student_columns(): void
    {
        $this->assertCount(Application::count(), $this->exportRows('APPLICATIONS'));

        $ids = $this->studentIdsWhere(['year' => 3, 'section' => 'A']);
        $rows = $this->exportRows('APPLICATIONS', ['year' => 3, 'section' => 'A']);
        $this->assertCount(Application::whereIn('student_id', $ids)->count(), $rows);
        $this->assertSame([3], collect($rows)->pluck('Year')->unique()->values()->all());
        $this->assertSame(['A'], collect($rows)->pluck('Section')->unique()->values()->all());

        $internshipRows = $this->exportRows('APPLICATIONS', ['department_id' => $this->aiml->id, 'applicable_type' => 'internship']);
        $expected = Application::where('applicable_type', Internship::class)
            ->whereIn('student_id', $this->studentIdsWhere(['department_id' => $this->aiml->id]))->count();
        $this->assertCount($expected, $internshipRows);
    }

    public function test_unplaced_report_matches_explicitly_not_placed_students(): void
    {
        $rows = $this->exportRows('UNPLACED_STUDENT_REPORT');
        $this->assertCount(Student::where('placement_status', 'NOT_PLACED')->count(), $rows);

        foreach ($rows as $row) {
            $student = Student::where('roll_number', $row['JNTU No'])->first();
            $this->assertEquals($student->applications()->count(), $row['Total Applications']);
            $this->assertEquals($student->applications()->whereNotIn('status', ['REJECTED', 'WITHDRAWN'])->count(), $row['Applications In Progress']);
        }

        $filtered = $this->exportRows('UNPLACED_STUDENT_REPORT', ['year' => 2, 'section' => 'B']);
        $this->assertCount(Student::where(['year' => 2, 'section' => 'B'])->where('placement_status', 'NOT_PLACED')->count(), $filtered);
    }

    public function test_section_wise_report_matches_class_headcounts_and_totals(): void
    {
        $rows = collect($this->exportRows('SECTION_WISE_PLACEMENT_REPORT'));
        $total = $rows->pop();
        $this->assertSame('Total', $total['Department']);

        $placed = Student::where('placement_status', 'PLACED')->pluck('id');
        $applied = Application::distinct()->pluck('student_id');

        $classes = Student::with('department')->get()->groupBy(fn ($s) => $s->department->name.'|'.$s->year.'|'.$s->section);
        $this->assertCount($classes->count(), $rows);

        foreach ($rows as $row) {
            $students = $classes->get($row['Department'].'|'.$row['Year'].'|'.($row['Section'] === '—' ? '' : $row['Section']));
            $this->assertNotNull($students, json_encode($row));
            $this->assertEquals($students->count(), $row['Total Students']);
            $this->assertEquals($students->whereIn('id', $applied)->count(), $row['Students Applied']);
            $this->assertEquals($students->whereIn('id', $placed)->count(), $row['Students Placed']);
            $this->assertEquals($students->where('placement_status', 'NOT_PLACED')->count(), $row['Students Unplaced']);
        }

        $this->assertEquals(Student::count(), $total['Total Students']);
        $this->assertEquals($placed->count(), $total['Students Placed']);
        $this->assertEquals(round($placed->count() / Student::count() * 100, 2), $total['Placement %']);
        $this->assertEquals(count($this->exportRows('UNPLACED_STUDENT_REPORT')), $total['Students Unplaced']);
    }

    public function test_department_wise_report_honours_year_and_section(): void
    {
        foreach ([[], ['year' => 3], ['year' => 2, 'section' => 'B']] as $filters) {
            $rows = collect($this->exportRows('DEPARTMENT_WISE_PLACEMENT_REPORT', $filters))->keyBy('Department');
            $scope = Student::where($filters);

            foreach ([$this->excelDepartment, $this->aiml] as $dept) {
                $ids = (clone $scope)->where('department_id', $dept->id)->pluck('id');
                $applications = Application::whereIn('student_id', $ids);
                $row = $rows->get($dept->name);

                if ((clone $applications)->count() === 0) {
                    $this->assertNull($row, json_encode($filters));

                    continue;
                }

                $this->assertEquals((clone $applications)->count(), $row['Total Applications'], json_encode($filters));
                $this->assertEquals((clone $applications)->distinct()->count('student_id'), $row['Distinct Students Applied']);
                $this->assertEquals((clone $applications)->where('status', 'SELECTED')->distinct()->count('student_id'), $row['Students Selected']);
            }
        }
    }

    public function test_application_summary_sheets_agree_under_every_filter(): void
    {
        foreach ([[], ['year' => 3, 'section' => 'A'], ['applicable_type' => 'job'], ['department_id' => $this->aiml->id, 'applicable_type' => 'internship']] as $filters) {
            $where = array_diff_key($filters, ['applicable_type' => true]);
            $query = Application::whereIn('student_id', Student::where($where)->pluck('id'));
            if (isset($filters['applicable_type'])) {
                $query->where('applicable_type', $filters['applicable_type'] === 'internship' ? Internship::class : \App\Models\JobPosting::class);
            }

            $overview = collect($this->exportSheet('APPLICATION_SUMMARY', $filters, 'Overview')->toArray())->pluck(1, 0);
            $byType = collect($this->exportRows('APPLICATION_SUMMARY', $filters, 'By Opportunity Type'));

            $this->assertEquals((clone $query)->count(), $overview['Total Applications'], json_encode($filters));
            $this->assertEquals((clone $query)->distinct()->count('student_id'), $overview['Distinct Students Who Applied']);
            $this->assertEquals($overview['Total Applications'], $byType->sum('Applications'), json_encode($filters));

            $status = collect($this->exportRows('STATUS_SUMMARY', $filters));
            $this->assertEquals($overview['Total Applications'], $status->sum('Applications'), json_encode($filters));
        }
    }

    public function test_student_application_report_counts_per_student(): void
    {
        $rows = $this->exportRows('STUDENT_APPLICATION_REPORT', ['department_id' => $this->excelDepartment->id]);
        $this->assertCount(Student::where('department_id', $this->excelDepartment->id)->count(), $rows);

        foreach ($rows as $row) {
            $student = Student::where('roll_number', $row['JNTU No'])->first();
            $this->assertEquals($student->applications()->count(), $row['Total Applications']);
            $this->assertEquals($student->applications()->where('status', 'SELECTED')->count(), $row['Selected']);
            $this->assertEquals($student->section, $row['Section']);
            $this->assertEquals($student->year, $row['Year']);
        }
    }

    public function test_activity_export_honours_section(): void
    {
        $ids = Application::whereIn('student_id', $this->studentIdsWhere(['section' => 'B']))->pluck('id');
        $rows = $this->exportRows('STUDENT_APPLICATION_ACTIVITY', ['section' => 'B']);

        $this->assertCount(\App\Models\ApplicationStatusHistory::whereIn('application_id', $ids)->count(), $rows);
        $this->assertSame(['B'], collect($rows)->pluck('Section')->unique()->values()->all());
    }

    public function test_student_status_filters_match_the_database_and_split_every_student(): void
    {
        $everShortlisted = \App\Models\ApplicationStatusHistory::where('to_status', 'SHORTLISTED')->pluck('application_id');
        $sets = [
            'applied' => Application::pluck('student_id'),
            'shortlisted' => Application::whereIn('id', $everShortlisted)->pluck('student_id'),
            'interviewed' => Application::has('interviews')->pluck('student_id'),
            'placed' => Student::where('placement_status', 'PLACED')->pluck('id'),
        ];
        $all = Student::pluck('roll_number')->sort()->values()->all();

        foreach ($sets as $status => $ids) {
            $opposite = $status === 'placed' ? 'unplaced' : 'not_'.$status;
            $expectedIn = Student::whereIn('id', $ids->unique())->pluck('roll_number')->sort()->values()->all();
            $expectedOut = Student::whereNotIn('id', $ids->unique())->pluck('roll_number')->sort()->values()->all();

            $in = collect($this->exportRows('STUDENTS', ['student_status' => $status]))->pluck('JNTU No')->sort()->values()->all();
            $out = collect($this->exportRows('STUDENTS', ['student_status' => $opposite]))->pluck('JNTU No')->sort()->values()->all();

            $this->assertSame($expectedIn, $in, $status);
            $this->assertSame($expectedOut, $out, $opposite);
            $this->assertSame($all, collect([...$in, ...$out])->sort()->values()->all(), $status.' + '.$opposite);
        }

        // Unplaced in the Students export = the Unplaced Student Report.
        $this->assertCount(count($this->exportRows('UNPLACED_STUDENT_REPORT')), $this->exportRows('STUDENTS', ['student_status' => 'unplaced']));

        // Combines with department/year/section.
        $filtered = $this->exportRows('STUDENT_APPLICATION_REPORT', ['student_status' => 'placed', 'year' => 3]);
        $this->assertCount(Student::where('year', 3)->whereIn('id', $sets['placed'])->count(), $filtered);

        Sanctum::actingAs($this->admin, ['*']);
        $this->getJson('/api/admin/excel/exports/STUDENTS?student_status=famous')->assertUnprocessable()->assertJsonValidationErrors('student_status');
    }

    public function test_application_status_filter_matches_the_database(): void
    {
        $sum = 0;

        foreach (\App\Enums\ApplicationStatus::values() as $status) {
            $rows = $this->exportRows('APPLICATIONS', ['status' => $status]);
            $this->assertCount(Application::where('status', $status)->count(), $rows, $status);
            $sum += count($rows);

            $sectionA = $this->exportRows('APPLICATIONS', ['status' => $status, 'section' => 'A']);
            $this->assertCount(Application::where('status', $status)->whereIn('student_id', $this->studentIdsWhere(['section' => 'A']))->count(), $sectionA, $status.' A');
        }

        $this->assertSame(Application::count(), $sum);
    }

    public function test_cgpa_and_backlog_filters_never_match_blank_values(): void
    {
        $cases = [
            [['cgpa_min' => 7.5], fn ($q) => $q->where('cgpa', '>=', 7.5)],
            [['cgpa_min' => 7, 'cgpa_max' => 8.5], fn ($q) => $q->whereBetween('cgpa', [7, 8.5])],
            [['max_backlogs' => 0], fn ($q) => $q->where('backlogs', '<=', 0)],
            [['backlogs' => 'some'], fn ($q) => $q->whereBetween('backlogs', [1, 2])],
            [['backlogs' => 'not_reported'], fn ($q) => $q->whereNull('backlogs')],
            [['cgpa_min' => 7, 'max_backlogs' => 0, 'year' => 3], fn ($q) => $q->where('cgpa', '>=', 7)->where('backlogs', '<=', 0)->where('year', 3)],
        ];

        foreach ($cases as [$filters, $where]) {
            $expected = $where(Student::query())->pluck('roll_number')->sort()->values()->all();
            $actual = collect($this->exportRows('STUDENTS', $filters))->pluck('JNTU No')->sort()->values()->all();
            $this->assertSame($expected, $actual, json_encode($filters));

            $applications = Application::whereIn('student_id', $where(Student::query())->pluck('id'))->count();
            $this->assertCount($applications, $this->exportRows('APPLICATIONS', $filters), json_encode($filters));
        }

        // Nobody without a CGPA slips into a CGPA range.
        $rows = $this->exportRows('STUDENTS', ['cgpa_min' => 0]);
        $this->assertNotContains('Not yet available', collect($rows)->pluck('CGPA')->all());
    }

    public function test_students_export_carries_the_full_college_record(): void
    {
        $student = Student::first();
        $student->update(['gender' => 'female', 'year_of_joining' => 2023, 'entry_type' => 'lateral', 'ssc_score' => 92.5, 'ssc_scale' => 'percent', 'inter_score' => 88, 'inter_type' => 'diploma', 'address' => 'Kakinada']);

        $row = collect($this->exportRows('STUDENTS'))->firstWhere('JNTU No', $student->roll_number);
        $this->assertSame('Female', $row['Gender']);
        $this->assertEquals(2023, $row['Year of Joining']);
        $this->assertSame('Lateral', $row['Entry Type']);
        $this->assertEquals(92.5, $row['10th Score']);
        $this->assertSame('%', $row['10th Scale']);
        $this->assertEquals(88, $row['Inter/Diploma Score']);
        $this->assertSame('Diploma', $row['Inter/Diploma']);
        $this->assertSame('Kakinada', $row['Address']);

        $this->assertCount(Student::where('entry_type', 'lateral')->count(), $this->exportRows('STUDENTS', ['entry_type' => 'lateral']));
    }

    public function test_eligible_students_matches_the_criteria_and_applications(): void
    {
        $job = \App\Models\JobPosting::where('title', 'Software Engineer')->first();
        $job->syncEligibility(['eligibility_enforced' => false, 'min_cgpa' => 7, 'max_backlogs' => 0, 'department_ids' => [$this->excelDepartment->id]]);

        $eligible = Student::where('department_id', $this->excelDepartment->id)->where('placement_status', 'NOT_PLACED')->where('cgpa', '>=', 7)->where('backlogs', '<=', 0)->pluck('id');
        $applied = Application::where('applicable_type', \App\Models\JobPosting::class)->where('applicable_id', $job->id)->pluck('status', 'student_id');

        $sheetTitle = \App\Services\Support\SheetTitleSanitizer::sanitize('NovaTech Solutions Software Engineer', ['Summary']);
        $rows = collect($this->exportRows('ELIGIBLE_STUDENTS', ['job_id' => $job->id], $sheetTitle));
        $summary = $this->exportRows('ELIGIBLE_STUDENTS', ['job_id' => $job->id], 'Summary')[0];

        $this->assertEquals(Student::count(), $summary['Students Checked']);
        $this->assertEquals($eligible->count(), $summary['Eligible']);
        $this->assertEquals($eligible->filter(fn ($id) => $applied->has($id))->count(), $summary['Eligible, Applied']);
        $this->assertEquals($eligible->reject(fn ($id) => $applied->has($id))->count(), $summary['Eligible, Not Applied']);
        $this->assertEquals($applied->keys()->diff($eligible)->count(), $summary['Applied, Not Eligible']);
        $this->assertSame($summary['Eligible'], $summary['Eligible, Applied'] + $summary['Eligible, Not Applied']);
        $this->assertStringContainsString('CGPA >= 7.00', $summary['Criteria']);

        $this->assertCount($eligible->count() + $summary['Applied, Not Eligible'], $rows);
        foreach ($rows as $row) {
            $student = Student::where('roll_number', $row['JNTU No'])->first();
            $this->assertSame($eligible->contains($student->id) ? 'Yes' : 'No', $row['Eligible'], $row['JNTU No']);
            $this->assertSame($applied->has($student->id) ? 'Yes' : 'No', $row['Applied']);
            if ($row['Eligible'] === 'No') {
                $this->assertNotSame('', $row['Why Not Eligible']);
            }
        }

        // Student filters narrow the pool; a drive covers each of its opportunities.
        $summaryYear2 = $this->exportRows('ELIGIBLE_STUDENTS', ['job_id' => $job->id, 'year' => 2], 'Summary')[0];
        $this->assertEquals(Student::where('year', 2)->count(), $summaryYear2['Students Checked']);

        $drive = \App\Models\PlacementDrive::create(['company_id' => $this->excelCompany->id, 'title' => 'Campus Drive', 'drive_type' => 'CAMPUS_PLACEMENT', 'status' => 'PUBLISHED', 'created_by' => $this->admin->id]);
        $drive->opportunities()->create(['opportunity_type' => \App\Models\JobPosting::class, 'opportunity_id' => $job->id]);
        $drive->opportunities()->create(['opportunity_type' => Internship::class, 'opportunity_id' => Internship::first()->id]);
        $driveSummary = $this->exportRows('ELIGIBLE_STUDENTS', ['drive_id' => $drive->id], 'Summary');
        $this->assertCount(2, $driveSummary);
        $this->assertEquals($summary['Eligible'], collect($driveSummary)->firstWhere('Role/Title', 'Software Engineer')['Eligible']);
        // No academic criteria on the internship; explicit placement
        // restrictions still apply.
        $this->assertEquals(Student::where('placement_status', 'NOT_PLACED')->count(), collect($driveSummary)->firstWhere('Type', 'Internship')['Eligible']);

        Sanctum::actingAs($this->admin, ['*']);
        $this->getJson('/api/admin/excel/exports/ELIGIBLE_STUDENTS')->assertUnprocessable();
    }

    public function test_package_report_matches_entered_offers(): void
    {
        $selectedJobs = Application::where('status', 'SELECTED')->where('applicable_type', \App\Models\JobPosting::class)->orderBy('id')->get();
        $this->assertCount(3, $selectedJobs);
        $packages = [600000, 1200000, null];

        Sanctum::actingAs($this->admin, ['*']);
        foreach ($selectedJobs as $i => $application) {
            $this->patchJson("/api/admin/applications/{$application->id}/offer", ['offer_package' => $packages[$i]])->assertOk();
        }
        // Not selected: refused.
        $pending = Application::where('status', '!=', 'SELECTED')->first();
        $this->patchJson("/api/admin/applications/{$pending->id}/offer", ['offer_package' => 500000])->assertUnprocessable();

        // A PPO for a fourth student, and a declined one that must not count.
        $fixture = $this->activatedInternshipAssignmentFixture($this->admin);
        \App\Models\PpoRecord::create(['internship_assignment_id' => $fixture['assignment_id'], 'company_id' => $this->excelCompany->id, 'package' => 900000, 'status' => 'ACCEPTED', 'designation' => 'SDE', 'offered_at' => now()->toDateString()]);
        $declined = $this->activatedInternshipAssignmentFixture($this->admin);
        \App\Models\PpoRecord::create(['internship_assignment_id' => $declined['assignment_id'], 'company_id' => $this->excelCompany->id, 'package' => 5000000, 'status' => 'DECLINED', 'designation' => 'SDE', 'offered_at' => now()->toDateString()]);

        $overview = collect($this->exportSheet('PACKAGE_REPORT', [], 'Overview')->toArray())->pluck(1, 0);
        // Best offers: 6, 12, 9 LPA (the third campus offer has no package).
        $this->assertEquals(4, $overview['Students with an offer']);
        $this->assertEquals(3, $overview['Students with a package entered']);
        $this->assertEquals(4, $overview['Total offers']);
        $this->assertEquals(1, $overview['Offers without a package entered']);
        $this->assertEquals(12, $overview['Highest package (LPA)']);
        $this->assertEquals(9, $overview['Average package (LPA)']);
        $this->assertEquals(9, $overview['Median package (LPA)']);
        $this->assertEquals(6, $overview['Lowest package (LPA)']);

        $best = collect($this->exportRows('PACKAGE_REPORT', [], 'Best Offer per Student'));
        $this->assertCount(4, $best);
        $this->assertSame('Not entered', $best->firstWhere('JNTU No', $selectedJobs[2]->student->roll_number)['Package (LPA)']);
        $this->assertSame('PPO', $best->firstWhere('Package (LPA)', 9)['Source']);

        $byDepartment = collect($this->exportRows('PACKAGE_REPORT', [], 'By Department'))->keyBy('Department');
        $cse = Student::where('department_id', $this->excelDepartment->id)->pluck('id');
        $expectedCse = collect([[$selectedJobs[0], 6], [$selectedJobs[1], 12], [$selectedJobs[2], null]])
            ->filter(fn ($p) => $cse->contains($p[0]->student_id) && $p[1] !== null)->pluck(1)->push(9);
        $this->assertEquals($expectedCse->count(), $byDepartment[$this->excelDepartment->name]['Students with Package']);
        $this->assertEquals($expectedCse->max(), $byDepartment[$this->excelDepartment->name]['Highest (LPA)']);

        // Department filter narrows it.
        $aimlOverview = collect($this->exportSheet('PACKAGE_REPORT', ['department_id' => $this->aiml->id], 'Overview')->toArray())->pluck(1, 0);
        $this->assertEquals(Application::where('status', 'SELECTED')->where('applicable_type', \App\Models\JobPosting::class)->whereIn('student_id', Student::where('department_id', $this->aiml->id)->pluck('id'))->count(), $aimlOverview['Total offers']);
    }

    public function test_package_can_be_entered_when_selecting(): void
    {
        $user = $this->makeStudentUser(['roll_number' => '23A91A9999', 'section' => 'A']);
        $job = $this->makeJobPosting(['title' => 'Cloud Engineer']);
        $id = $this->applyAndAdvance($this->admin, $user, $job, ['UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT']);

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson("/api/admin/applications/{$id}/status", ['status' => 'SELECTED', 'offer_package' => 750000])
            ->assertOk()->assertJsonPath('data.offer_package', 750000);
        $this->assertEquals(750000, Application::find($id)->offer_package);
    }

    public function test_batch_comparison_matches_department_and_batch_counts(): void
    {
        $rows = collect($this->exportRows('BATCH_COMPARISON'));
        $total = $rows->pop();
        $placed = Student::where('placement_status', 'PLACED')->pluck('id');

        foreach ([$this->batch2023, $this->batch2024] as $batch) {
            foreach ([$this->excelDepartment, $this->aiml] as $dept) {
                $ids = Student::where(['batch_id' => $batch->id, 'department_id' => $dept->id])->pluck('id');
                $row = $rows->firstWhere('Department', $dept->name);
                $this->assertEquals($ids->count(), $row["{$batch->name} Students"], "{$dept->code} {$batch->name}");
                $this->assertEquals($ids->intersect($placed)->count(), $row["{$batch->name} Placed"]);
            }

            $inBatch = Student::where('batch_id', $batch->id)->pluck('id');
            $this->assertEquals($inBatch->count(), $total["{$batch->name} Students"]);
            $this->assertEquals($inBatch->intersect($placed)->count(), $total["{$batch->name} Placed"]);
            $this->assertEquals(round($inBatch->intersect($placed)->count() / $inBatch->count() * 100, 2), $total["{$batch->name} Placement %"]);
        }
    }

    public function test_section_and_year_filters_are_validated(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/admin/excel/exports/STUDENTS?year=9')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/admin/excel/exports/STUDENTS?section='.str_repeat('A', 11))->assertUnprocessable()->assertJsonValidationErrors('section');
    }
}
