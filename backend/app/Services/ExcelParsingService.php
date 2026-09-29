<?php

namespace App\Services;

use App\Enums\ExcelImportType;
use App\Exceptions\ExcelImportException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads an uploaded XLSX/XLS file (via PhpOffice/PhpSpreadsheet) into a
 * plain, normalized array of rows - the FIRST step of the module
 * spec's section 1 pipeline ("Excel -> Parse -> Normalize -> Match
 * against MySQL -> ..."). This class only reads and normalizes column
 * NAMES/cell text; it never touches MySQL and never decides whether a
 * row is valid - that is App\Services\ExcelMatchingService/
 * ExcelValidationService's job (module spec section 34's pipeline
 * diagram keeps these as separate stages on purpose).
 *
 * Column matching is header-name based, not position based - a
 * placement cell's own Excel export rarely has its columns in a fixed
 * order, and the module spec explicitly asks for "human-readable
 * identifiers" rather than a rigid template. Header text is normalized
 * the same way the spec asks status VALUES to be normalized (module
 * spec section 5/7: trim, case-insensitive, collapse internal
 * whitespace) - "Roll No", "roll no", "  Roll   No  " and "ROLL NO" all
 * resolve to the same column.
 */
class ExcelParsingService
{
    /**
     * Hard ceiling on data rows read from one file - not in the module
     * spec, but "bulk" does not mean "unbounded"; a single request
     * parsing, matching, and validating an arbitrarily large sheet
     * inside one HTTP request/DB transaction is a resource-exhaustion
     * risk (module spec section 33's security requirements list file
     * size limits for the same underlying reason). 5,000 rows is
     * generous for a single placement-cycle company results/interview/
     * attendance sheet while still bounding worst-case memory/time.
     */
    private const MAX_ROWS = 5000;

    /**
     * @return array{key: string, header: string, aliases: array<int, string>, required: bool}[]
     */
    public function columnMapFor(ExcelImportType $type): array
    {
        return match (true) {
            $type === ExcelImportType::STUDENT_MASTER => [
                $this->col('roll_number', 'Roll Number', ['Roll No', 'Registration Number', 'Reg No'], required: true),
                $this->col('name', 'Name', ['Student Name'], required: false),
                $this->col('department', 'Department', ['Branch', 'Dept'], required: false),
                $this->col('batch', 'Batch', [], required: false),
                $this->col('year', 'Year', [], required: false),
                $this->col('phone', 'Phone', ['Mobile', 'Phone Number'], required: false),
                $this->col('personal_email', 'Personal Email', ['Email'], required: false),
                $this->col('cgpa', 'CGPA', [], required: false),
                $this->col('backlogs', 'Backlogs', [], required: false),
                $this->col('linkedin', 'LinkedIn', ['LinkedIn URL'], required: false),
                $this->col('github', 'GitHub', ['GitHub URL'], required: false),
                $this->col('portfolio', 'Portfolio', ['Portfolio URL'], required: false),
            ],
            $type->isApplicationStatusFamily() => [
                $this->col('student_name', 'Student Name', ['Name'], required: false),
                $this->col('roll_number', 'Roll Number', ['Roll No', 'Registration Number'], required: true),
                $this->col('branch', 'Branch', ['Department'], required: false),
                $this->col('batch', 'Batch', [], required: false),
                $this->col('company', 'Company', [], required: true),
                $this->col('job_role', 'Job Role', ['Role', 'Position', 'Title'], required: true),
                $this->col('status', 'Status', [], required: true),
                $this->col('remarks', 'Remarks', ['Notes', 'Comments'], required: false),
            ],
            $type === ExcelImportType::INTERNSHIP_STATUS => [
                $this->col('student_name', 'Student Name', ['Name'], required: false),
                $this->col('roll_number', 'Roll Number', ['Roll No'], required: true),
                $this->col('company', 'Company', [], required: true),
                $this->col('internship_role', 'Internship Role', ['Job Role', 'Role'], required: true),
                $this->col('start_date', 'Start Date', [], required: false),
                $this->col('end_date', 'End Date', [], required: false),
                $this->col('status', 'Status', [], required: false),
                $this->col('ppo_status', 'PPO Status', [], required: false),
            ],
            $type === ExcelImportType::INTERVIEW_SCHEDULE => [
                $this->col('student_name', 'Student Name', ['Name'], required: false),
                $this->col('roll_number', 'Roll Number', ['Roll No'], required: true),
                $this->col('company', 'Company', [], required: true),
                $this->col('job_role', 'Job Role', ['Role', 'Position'], required: true),
                $this->col('status', 'Status', [], required: true),
                $this->col('interview_date', 'Interview Date', [], required: true),
                $this->col('interview_time', 'Interview Time', [], required: false),
                $this->col('mode', 'Mode', [], required: false),
                $this->col('venue', 'Venue', [], required: false),
                $this->col('meeting_link', 'Meeting Link', ['Meeting URL'], required: false),
            ],
            $type === ExcelImportType::ATTENDANCE => [
                $this->col('roll_number', 'Roll Number', ['Roll No'], required: true),
                $this->col('student_name', 'Student Name', ['Name'], required: false),
                $this->col('internship', 'Internship', ['Company'], required: true),
                $this->col('attendance_date', 'Attendance Date', ['Date'], required: true),
                $this->col('status', 'Status', [], required: true),
                $this->col('check_in', 'Check In', ['Check In Time'], required: false),
                $this->col('check_out', 'Check Out', ['Check Out Time'], required: false),
            ],
            default => throw new ExcelImportException('Unsupported import type.'),
        };
    }

    /**
     * @return array{header_row: array<int, string>, rows: list<array{row_number: int, data: array<string, ?string>}>}
     */
    public function parse(string $absolutePath, ExcelImportType $type): array
    {
        if (! is_file($absolutePath)) {
            throw new ExcelImportException('The uploaded file could not be found.', status: 500);
        }

        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolutePath);
        } catch (\Throwable $e) {
            throw new ExcelImportException('The uploaded file could not be read as an Excel workbook. Please upload a valid .xlsx or .xls file.');
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        if ($highestRow < 2) {
            throw new ExcelImportException('The uploaded file has no data rows below the header row.');
        }

        // --- header row -----------------------------------------------------
        $rawHeaders = [];

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).'1';
            $rawHeaders[$col] = (string) $sheet->getCell($coordinate)->getValue();
        }

        $columnMap = $this->columnMapFor($type);
        $keyByColumnIndex = [];

        foreach ($rawHeaders as $col => $rawHeader) {
            $normalized = $this->normalizeHeader($rawHeader);

            if ($normalized === '') {
                continue;
            }

            foreach ($columnMap as $definition) {
                $candidates = array_map($this->normalizeHeader(...), [$definition['header'], ...$definition['aliases']]);

                if (in_array($normalized, $candidates, true)) {
                    $keyByColumnIndex[$col] = $definition['key'];

                    break;
                }
            }
        }

        $missingRequired = [];

        foreach ($columnMap as $definition) {
            if ($definition['required'] && ! in_array($definition['key'], $keyByColumnIndex, true)) {
                $missingRequired[] = $definition['header'];
            }
        }

        if ($missingRequired !== []) {
            throw new ExcelImportException(
                'The uploaded file is missing required column(s): '.implode(', ', $missingRequired).'. Please use the official template.',
            );
        }

        // --- data rows --------------------------------------------------------
        $dataRowCount = $highestRow - 1;

        if ($dataRowCount > self::MAX_ROWS) {
            throw new ExcelImportException(sprintf(
                'This file has %d data rows, which exceeds the %d-row limit per import. Please split it into smaller files.',
                $dataRowCount,
                self::MAX_ROWS,
            ));
        }

        $rows = [];

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $data = [];
            $isBlank = true;

            foreach ($keyByColumnIndex as $col => $key) {
                $coordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).$rowIndex;
                $cell = $sheet->getCell($coordinate);
                $value = $this->readCellValue($cell, $key);

                if ($value !== null && $value !== '') {
                    $isBlank = false;
                }

                $data[$key] = $value;
            }

            if ($isBlank) {
                continue;
            }

            $rows[] = ['row_number' => $rowIndex, 'data' => $data];
        }

        if ($rows === []) {
            throw new ExcelImportException('The uploaded file has no data rows below the header row.');
        }

        return ['header_row' => $rawHeaders, 'rows' => $rows];
    }

    /**
     * @param array<int, string> $aliases
     * @return array{key: string, header: string, aliases: array<int, string>, required: bool}
     */
    private function col(string $key, string $header, array $aliases, bool $required): array
    {
        return ['key' => $key, 'header' => $header, 'aliases' => $aliases, 'required' => $required];
    }

    /**
     * "Interview Date"/"Attendance Date"-shaped columns come back from
     * PhpSpreadsheet either as an Excel serial date float (when the
     * source cell is genuinely date-formatted) or as a plain string
     * (when the placement cell typed/pasted "12/03/2026" into a
     * text-formatted cell, which is extremely common in ad-hoc company
     * sheets) - both are normalized to a plain Y-m-d string here so
     * every downstream consumer (ExcelValidationService) only ever
     * deals with one shape, never PhpSpreadsheet's own types.
     */
    private function readCellValue(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell, string $key): ?string
    {
        $isDateLike = in_array($key, ['start_date', 'end_date', 'interview_date', 'attendance_date'], true);
        $rawValue = $cell->getValue();

        if ($rawValue === null) {
            return null;
        }

        if ($isDateLike && is_numeric($rawValue) && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
            $dateTime = ExcelDate::excelToDateTimeObject($rawValue);

            return $dateTime->format('Y-m-d');
        }

        if ($key === 'interview_time' || $key === 'check_in' || $key === 'check_out') {
            if (is_numeric($rawValue) && $rawValue < 1) {
                // A bare time-of-day cell (e.g. 10:30 AM) is stored by
                // Excel as a fraction of a 24-hour day.
                $seconds = (int) round($rawValue * 86400);

                return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
            }
        }

        $value = trim((string) $rawValue);

        return $value === '' ? null : $value;
    }

    private function normalizeHeader(string $header): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($header)) ?? '';

        // The downloadable templates mark required columns with a
        // trailing " *" (ExcelTemplateService::REQUIRED_MARK). Strip it
        // here so a file that keeps the template's own headers - i.e.
        // the official template itself - matches the column map.
        $collapsed = trim(preg_replace('/\s*\*+$/', '', $collapsed) ?? $collapsed);

        return mb_strtolower($collapsed);
    }
}
