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

    public function test_unplaced_report_matches_students_without_a_selected_application(): void
    {
        $placed = Application::where('status', 'SELECTED')->distinct()->pluck('student_id');

        $rows = $this->exportRows('UNPLACED_STUDENT_REPORT');
        $this->assertCount(Student::whereNotIn('id', $placed)->count(), $rows);

        foreach ($rows as $row) {
            $student = Student::where('roll_number', $row['JNTU No'])->first();
            $this->assertEquals($student->applications()->count(), $row['Total Applications']);
            $this->assertEquals($student->applications()->whereNotIn('status', ['REJECTED', 'WITHDRAWN'])->count(), $row['Applications In Progress']);
        }

        $filtered = $this->exportRows('UNPLACED_STUDENT_REPORT', ['year' => 2, 'section' => 'B']);
        $this->assertCount(Student::where(['year' => 2, 'section' => 'B'])->whereNotIn('id', $placed)->count(), $filtered);
    }

    public function test_section_wise_report_matches_class_headcounts_and_totals(): void
    {
        $rows = collect($this->exportRows('SECTION_WISE_PLACEMENT_REPORT'));
        $total = $rows->pop();
        $this->assertSame('Total', $total['Department']);

        $placed = Application::where('status', 'SELECTED')->distinct()->pluck('student_id');
        $applied = Application::distinct()->pluck('student_id');

        $classes = Student::with('department')->get()->groupBy(fn ($s) => $s->department->name.'|'.$s->year.'|'.$s->section);
        $this->assertCount($classes->count(), $rows);

        foreach ($rows as $row) {
            $students = $classes->get($row['Department'].'|'.$row['Year'].'|'.($row['Section'] === '—' ? '' : $row['Section']));
            $this->assertNotNull($students, json_encode($row));
            $this->assertEquals($students->count(), $row['Total Students']);
            $this->assertEquals($students->whereIn('id', $applied)->count(), $row['Students Applied']);
            $this->assertEquals($students->whereIn('id', $placed)->count(), $row['Students Placed']);
            $this->assertEquals($students->whereNotIn('id', $placed)->count(), $row['Students Unplaced']);
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

    public function test_section_and_year_filters_are_validated(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/admin/excel/exports/STUDENTS?year=9')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/admin/excel/exports/STUDENTS?section='.str_repeat('A', 11))->assertUnprocessable()->assertJsonValidationErrors('section');
    }
}
