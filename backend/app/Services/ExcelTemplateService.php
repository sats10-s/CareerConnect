<?php

namespace App\Services;

use App\Enums\ExcelImportType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Builds the 8 downloadable blank import templates (module spec
 * section 18) - one XLSX per App\Enums\ExcelImportType, with only the
 * human-readable header row every column in
 * App\Services\ExcelParsingService::columnMapFor() expects plus one
 * greyed-out illustrative example row (never a real record - always
 * clearly fictional placeholder text) so an admin knows the expected
 * shape without having to guess.
 *
 * Deliberately reuses ExcelParsingService::columnMapFor() as the SINGLE
 * source of truth for each import type's columns - a template that
 * drifted from what the parser actually accepts would be worse than no
 * template at all (module spec's own "use the official template"
 * error message, thrown by ExcelParsingService itself, only makes
 * sense if the template and the parser can never disagree).
 */
class ExcelTemplateService
{
    private const REQUIRED_MARK = ' *';

    public function __construct(private readonly ExcelParsingService $parsingService) {}

    public function build(ExcelImportType $type): Spreadsheet
    {
        $columns = $this->parsingService->columnMapFor($type);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($type->label(), 0, 31));

        $headers = array_map(
            fn (array $c) => $c['header'].($c['required'] ? self::REQUIRED_MARK : ''),
            $columns,
        );

        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');

        $example = $this->exampleRow($type, $columns);
        $sheet->fromArray($example, null, 'A2');
        $sheet->getStyle('A2:'.$lastColumn.'2')->getFont()->setItalic(true);
        $sheet->getStyle('A2:'.$lastColumn.'2')->getFont()->getColor()->setARGB('FF94A3B8');

        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    /**
     * A single illustrative row of clearly-fictional example values,
     * keyed by the same column order columnMapFor() returned - never
     * real student/company data, and never inserted into the parsed
     * "data rows" range (row 2 is styled distinctly in italic grey
     * specifically so nobody mistakes it for real data and forgets to
     * delete it before filling the template in).
     *
     * @param array<int, array{key: string, header: string, aliases: array<int, string>, required: bool}> $columns
     * @return list<string>
     */
    private function exampleRow(ExcelImportType $type, array $columns): array
    {
        $samples = match (true) {
            $type === ExcelImportType::STUDENT_MASTER => [
                'roll_number' => 'CS2023101', 'name' => 'Asha Rao', 'department' => 'Computer Science',
                'batch' => '2023-2027', 'year' => '3', 'phone' => '9876543210', 'personal_email' => 'asha.rao@example.com',
                'cgpa' => '8.4', 'backlogs' => '0', 'linkedin' => 'https://linkedin.com/in/asharao',
                'github' => 'https://github.com/asharao', 'portfolio' => 'https://asharao.dev',
            ],
            $type->isApplicationStatusFamily() => [
                'student_name' => 'Asha Rao', 'roll_number' => 'CS2023101', 'branch' => 'Computer Science',
                'batch' => '2023-2027', 'company' => 'Acme Corp', 'job_role' => 'Software Engineer Intern',
                'status' => 'SHORTLISTED', 'remarks' => 'Strong technical round.',
            ],
            $type === ExcelImportType::INTERNSHIP_STATUS => [
                'student_name' => 'Asha Rao', 'roll_number' => 'CS2023101', 'company' => 'Acme Corp',
                'internship_role' => 'Backend Internship', 'start_date' => '2026-01-15', 'end_date' => '2026-06-15',
                'status' => 'ACTIVE', 'ppo_status' => 'OFFERED',
            ],
            $type === ExcelImportType::INTERVIEW_SCHEDULE => [
                'student_name' => 'Asha Rao', 'roll_number' => 'CS2023101', 'company' => 'Acme Corp',
                'job_role' => 'Software Engineer Intern', 'status' => 'SCHEDULED', 'interview_date' => '2026-10-12',
                'interview_time' => '10:30', 'mode' => 'ONLINE', 'venue' => '', 'meeting_link' => 'https://meet.example.com/xyz',
            ],
            $type === ExcelImportType::ATTENDANCE => [
                'roll_number' => 'CS2023101', 'student_name' => 'Asha Rao', 'internship' => 'Backend Internship',
                'attendance_date' => '2026-09-21', 'status' => 'PRESENT', 'check_in' => '09:30', 'check_out' => '18:00',
            ],
            default => [],
        };

        return array_map(fn (array $c) => $samples[$c['key']] ?? '', $columns);
    }
}
