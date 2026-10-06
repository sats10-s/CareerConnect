<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A synthetic copy of the college's student file - fake names and phone
 * numbers, real quirks. Two sheets with the same 11 columns (the second
 * with differently-cased headers), and every oddity the real file has:
 *
 *  - "Section A" next to plain "A", a lowercase "c", an out-of-range "E"
 *  - #N/A sections (an Excel error cell and the literal text)
 *  - a row with no JNTU No, a duplicate JNTU No, an unknown department
 *  - a CGPA above 10, blank backlogs
 *  - blank Year of Joining for 5A laterals
 *  - 1D M.Tech students, who are out of scope and skipped
 *  - department aliases: CSE, AIML, AI&ML, AI&DS, CE, Civil, ME, MECH,
 *    ECE, EEE, IT
 *  - 10th on a 10-point scale (9.8, 9.5) including "10 POINTS OUT 10"
 *  - mobiles as numbers and as text, one with a space, one with 11 digits,
 *    one missing
 *  - gender as F/M and as female/male
 *
 * Expected preview (see StudentDataImportTest):
 *   18 rows; 11 new (7 clean + 4 with warnings), 7 errors (2 of them M.Tech);
 *   2 one-time passwords.
 */
trait BuildsCollegeStudentFile
{
    protected const COLLEGE_HEADERS = ['Name', 'JNTU No', 'Year of Joining', 'Gender', 'Department', 'Section', 'Mobile No', 'CGPA', 'No. of Backlogs', '10th Percentage', 'Inter Percentage/Diploma'];

    /** Sheet 1: regular 2023 batch. Index = row position (sheet row = index + 2). */
    protected function regularSheetRows(): array
    {
        return [
            ['Aarav Test', '23341A0501', 2023, 'M', 'CSE', 'Section A', 9876500001, 8.05, 0, 92.33, 95.2],          // new
            ['Bhavya  Test', '23341A0502', 2023, 'F', 'CSE', 'A', '9876500002', 7.5, 1, 9.8, 88],                 // new, 10th as CGPA, double space in name
            ['Chirag Test', '23341A6601', 2023, 'male', 'AIML', 'B', 9876500003, 8.9, null, '10 POINTS OUT 10', 91], // new, backlogs not reported
            ['Divya Test', '23341A6602', 2023, 'female', 'AI&ML', 'Section B', 9876500004, 78.5, 0, 85, 90],     // new + warning: CGPA > 10
            ['Eshan Test', '23341A0101', 2023, 'M', 'Civil', 'C', 98765000055, 6.9, 2, 80, 75],                  // new + warning: 11-digit mobile -> OTP
            ['Farah Test', '23341A0102', 2023, 'F', 'CE', '#ERROR#', 9876500006, 7.2, 0, 82, 79],                // error: #N/A section (error cell)
            ['Ghost Test', null, 2023, 'M', 'CSE', 'A', 9876500007, 7.0, 0, 70, 70],                              // error: no JNTU No
            ['Hari Test', '23341A0301', 2023, 'M', 'ME', 'D', 9876500008, 7.1, 0, 88, 80],                       // new
            ['Isha Test', '23341A0302', 2023, 'F', 'MECH', 'E', 9876500009, 7.7, 0, 90, 85],                     // new + warning: section E
            ['Jay Test', '23341A3001', 2023, 'M', 'Robotics', 'A', 9876500010, 8.0, 0, 85, 85],                  // error: unknown department
            ['Kiran Test', '23341A0501', 2023, 'M', 'CSE', 'A', 9876500011, 8.0, 0, 85, 85],                     // error: duplicate of Aarav
            ['Lata Test', '23341A5401', 2023, 'F', 'AI&DS', 'A', '98765 00012', 8.4, 0, 93, 96],                 // new, mobile with a space
        ];
    }

    /** Sheet 2: laterals (5A) and M.Tech (1D, skipped) - Year of Joining left blank. */
    protected function lateralSheetRows(): array
    {
        return [
            ['Mohan Test', '24345A0501', null, 'M', 'CSE', 'A', 9876500013, 7.9, 0, 85, 82],                     // new lateral -> 2023 batch, diploma
            ['Neha Test', '24345A0401', null, 'F', 'ECE', 'Section B', 9876500014, 8.2, null, 9.5, 78],          // new lateral
            ['Om Test', '24341D5701', null, 'M', 'VLSI&ES', 'A', 9876500015, 8.8, 0, 90, 85],                    // error: M.Tech is not imported
            ['Pooja Test', '24341D5702', null, 'F', 'CSE', 'A', 9876500016, 9.1, 0, 94, 89],                     // error: M.Tech is not imported
            ['Ravi Test', '24345A0201', null, 'male', 'EEE', '#N/A', 9876500017, 7.4, 1, 77, 70],                // error: "#N/A" as text
            ['Sita Test', '23341A1201', 2023, 'female', 'IT', 'c', null, 8.6, 0, 91, 92],                        // new + warning: no mobile -> OTP
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>|null  $regular  override sheet 1's rows
     * @param  array<int, array<int, mixed>>|null  $lateral  override sheet 2's rows
     */
    protected function collegeStudentFile(?array $regular = null, ?array $lateral = null, string $filename = 'college_students.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $first = $spreadsheet->getActiveSheet();
        $first->setTitle('2023 Batch');
        $this->fillSheet($first, self::COLLEGE_HEADERS, $regular ?? $this->regularSheetRows());

        $second = $spreadsheet->createSheet();
        $second->setTitle('Lateral & MTech');
        // Same columns, different header spelling.
        $this->fillSheet($second, ['NAME', 'JNTU NO', 'YEAR OF JOINING', 'GENDER', 'DEPARTMENT', 'SECTION', 'MOBILE NO.', 'CGPA', 'NO OF BACKLOGS', '10TH PERCENTAGE', 'INTER PERCENTAGE / DIPLOMA'], $lateral ?? $this->lateralSheetRows());

        return $this->saveUpload($spreadsheet, $filename);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    protected function resultsUpdateFile(array $headers, array $rows, string $filename = 'results.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $this->fillSheet($spreadsheet->getActiveSheet(), $headers, $rows);

        return $this->saveUpload($spreadsheet, $filename);
    }

    private function fillSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $rows): void
    {
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            foreach (array_values($row) as $col => $value) {
                $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1).$line;
                if ($value === null) {
                    continue;
                }
                if ($value === '#ERROR#') {
                    // A real Excel error cell, as left behind by a failed VLOOKUP.
                    $sheet->getCell($coordinate)->setValueExplicit('#N/A', DataType::TYPE_ERROR);
                } elseif (is_string($value)) {
                    $sheet->getCell($coordinate)->setValueExplicit($value, DataType::TYPE_STRING);
                } else {
                    $sheet->getCell($coordinate)->setValue($value);
                }
            }
        }
    }

    private function saveUpload(Spreadsheet $spreadsheet, string $filename): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'college_file_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
