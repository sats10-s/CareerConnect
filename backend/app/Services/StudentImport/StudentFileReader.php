<?php

namespace App\Services\StudentImport;

use App\Exceptions\ExcelImportException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the college's student workbook: EVERY sheet, not just the active
 * one (the college sends one sheet per year/stream with the same headers).
 *
 * Header matching is loose - case, spaces and punctuation are ignored
 * ("No. of Backlogs", "no of backlogs", "NO_OF_BACKLOGS" are the same) and
 * common alternative names are accepted. The header row is the first of the
 * top 10 rows that contains a JNTU No column, so a title row above the
 * headers doesn't break the import.
 *
 * Cell values come back as raw scalars (int/float/string/null) - the
 * cleaning rules (StudentRowCleaner) need to know whether a mobile number
 * was typed as a number or as text. Formula cells use the value Excel last
 * calculated; error cells (#N/A, #REF!) come back as their error text.
 */
class StudentFileReader
{
    private const MAX_ROWS = 6000;

    private const HEADER_SCAN_ROWS = 10;

    /** normalized header => column key */
    private const ALIASES = [
        'name' => ['name', 'studentname', 'nameofthestudent', 'fullname'],
        'roll_number' => ['jntuno', 'jntunumber', 'jntu', 'rollno', 'rollnumber', 'hallticketno', 'hallticketnumber', 'htno', 'regdno', 'regno', 'registrationnumber', 'admissionno'],
        'year_of_joining' => ['yearofjoining', 'joiningyear', 'yoj', 'admissionyear', 'yearofadmission'],
        'gender' => ['gender', 'sex'],
        'department' => ['department', 'dept', 'branch'],
        'section' => ['section', 'sec'],
        'phone' => ['mobileno', 'mobile', 'mobilenumber', 'phone', 'phoneno', 'phonenumber', 'contactno', 'contactnumber'],
        'cgpa' => ['cgpa', 'gpa', 'currentcgpa'],
        'backlogs' => ['noofbacklogs', 'backlogs', 'numberofbacklogs', 'activebacklogs', 'backlog'],
        'ssc' => ['10thpercentage', '10th', 'ssc', 'sscpercentage', 'tenthpercentage', '10thmarks', 'tenth', 'sscmarks'],
        'inter' => ['interpercentagediploma', 'interpercentage', 'interdiploma', 'interordiploma', 'diploma', 'intermediate', 'inter', '12thpercentage', 'intermediatepercentage', 'diplomapercentage'],
        'semester' => ['semester', 'sem', 'semesterlabel'],
    ];

    public static function normalizeHeader(?string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim((string) $header)));
    }

    public static function keyForHeader(?string $header): ?string
    {
        $normalized = self::normalizeHeader($header);

        if ($normalized === '') {
            return null;
        }

        foreach (self::ALIASES as $key => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $requiredKeys  columns a sheet must have to be read at all
     * @return array{
     *     sheets: list<array{name: string, rows: int, skipped: bool, reason: ?string, columns: list<string>}>,
     *     rows: list<array{sheet: string, sheet_row: int, data: array<string, int|float|string|null>}>
     * }
     */
    public function read(string $absolutePath, array $requiredKeys): array
    {
        if (! is_file($absolutePath)) {
            throw new ExcelImportException('The uploaded file could not be found.', status: 500);
        }

        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolutePath);
        } catch (\Throwable) {
            throw new ExcelImportException('The uploaded file could not be read as an Excel workbook. Please upload a valid .xlsx or .xls file.');
        }

        $sheets = [];
        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $result = $this->readSheet($sheet, $requiredKeys);
            $sheets[] = $result['summary'];
            array_push($rows, ...$result['rows']);

            if (count($rows) > self::MAX_ROWS) {
                $spreadsheet->disconnectWorksheets();

                throw new ExcelImportException(sprintf('This file has more than %d student rows. Please split it into smaller files.', self::MAX_ROWS));
            }
        }

        // Workbooks hold circular references; free them now rather than at exit.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if ($rows === []) {
            $reasons = array_values(array_filter(array_map(fn ($s) => $s['reason'] ? ['code' => 'SHEET_SKIPPED', 'message' => "{$s['name']}: {$s['reason']}"] : null, $sheets)));

            throw new ExcelImportException('No student rows were found in this file.', $reasons);
        }

        return ['sheets' => $sheets, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $requiredKeys
     */
    private function readSheet(Worksheet $sheet, array $requiredKeys): array
    {
        $name = $sheet->getTitle();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $headerRow = null;
        $columns = [];

        for ($row = 1; $row <= min(self::HEADER_SCAN_ROWS, $highestRow); $row++) {
            $candidate = [];
            for ($col = 1; $col <= $highestColumn; $col++) {
                $key = self::keyForHeader((string) $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getValue());
                if ($key !== null && ! in_array($key, $candidate, true)) {
                    $candidate[$col] = $key;
                }
            }

            if (in_array('roll_number', $candidate, true)) {
                $headerRow = $row;
                $columns = $candidate;

                break;
            }
        }

        if ($headerRow === null) {
            return ['summary' => ['name' => $name, 'rows' => 0, 'skipped' => true, 'reason' => 'No "JNTU No" column found, so this sheet was skipped.', 'columns' => []], 'rows' => []];
        }

        $missing = array_values(array_diff($requiredKeys, $columns));
        if ($missing !== []) {
            return ['summary' => ['name' => $name, 'rows' => 0, 'skipped' => true, 'reason' => 'Missing column(s): '.implode(', ', array_map(self::label(...), $missing)).'.', 'columns' => array_values($columns)], 'rows' => []];
        }

        $rows = [];
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $data = [];
            $blank = true;

            foreach ($columns as $col => $key) {
                $value = $this->cellValue($sheet->getCell(Coordinate::stringFromColumnIndex($col).$row));
                if ($value !== null && $value !== '') {
                    $blank = false;
                }
                $data[$key] = $value;
            }

            if (! $blank) {
                $rows[] = ['sheet' => $name, 'sheet_row' => $row, 'data' => $data];
            }
        }

        return ['summary' => ['name' => $name, 'rows' => count($rows), 'skipped' => false, 'reason' => null, 'columns' => array_values($columns)], 'rows' => $rows];
    }

    private function cellValue(Cell $cell): int|float|string|null
    {
        $type = $cell->getDataType();

        if ($type === DataType::TYPE_FORMULA) {
            $value = $cell->getOldCalculatedValue();
        } else {
            $value = $cell->getValue();
        }

        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    public static function label(string $key): string
    {
        return match ($key) {
            'name' => 'Name',
            'roll_number' => 'JNTU No',
            'year_of_joining' => 'Year of Joining',
            'gender' => 'Gender',
            'department' => 'Department',
            'section' => 'Section',
            'phone' => 'Mobile No',
            'cgpa' => 'CGPA',
            'backlogs' => 'No. of Backlogs',
            'ssc' => '10th Percentage',
            'inter' => 'Inter Percentage/Diploma',
            'semester' => 'Semester',
            default => $key,
        };
    }
}
