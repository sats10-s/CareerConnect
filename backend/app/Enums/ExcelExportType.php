<?php

namespace App\Enums;

/**
 * Every downloadable export the Excel Sync Center offers - module spec
 * sections 19-31: RAW exports are one sheet, one row per database
 * record, straight off the relevant table(s); ANALYTICAL exports are
 * computed server-side with MySQL aggregation (GROUP BY/COUNT/DISTINCT
 * - see App\Services\ExcelExportService's own docblock for why this is
 * never done in PHP userland) and may span several sheets in one
 * workbook. isRaw()/isAnalytical() let the controller and
 * ExcelExportService dispatch without a second, duplicated type list.
 */
enum ExcelExportType: string
{
    // --- raw data exports (module spec section 19: 11 listed types) ---
    case STUDENTS = 'STUDENTS';
    case APPLICATIONS = 'APPLICATIONS';
    case INTERVIEWS = 'INTERVIEWS';
    case INTERNSHIP_ASSIGNMENTS = 'INTERNSHIP_ASSIGNMENTS';
    case PPO_RECORDS = 'PPO_RECORDS';
    case ATTENDANCE = 'ATTENDANCE';
    case INTERNSHIP_COMPLETIONS = 'INTERNSHIP_COMPLETIONS';
    case WEEKLY_PROGRESS_REPORTS = 'WEEKLY_PROGRESS_REPORTS';
    case INTERNSHIP_DOCUMENTS = 'INTERNSHIP_DOCUMENTS';
    case PLACEMENT_DRIVES = 'PLACEMENT_DRIVES';
    case COMPANIES = 'COMPANIES';

    // --- analytical / summary exports (module spec sections 20-31) ---
    case APPLICATION_SUMMARY = 'APPLICATION_SUMMARY';
    case STATUS_SUMMARY = 'STATUS_SUMMARY';
    case COMPANY_WISE_PLACEMENT_REPORT = 'COMPANY_WISE_PLACEMENT_REPORT';
    case DEPARTMENT_WISE_PLACEMENT_REPORT = 'DEPARTMENT_WISE_PLACEMENT_REPORT';
    case BATCH_WISE_PLACEMENT_REPORT = 'BATCH_WISE_PLACEMENT_REPORT';
    case STUDENT_APPLICATION_REPORT = 'STUDENT_APPLICATION_REPORT';
    case UNPLACED_STUDENT_REPORT = 'UNPLACED_STUDENT_REPORT';
    case STUDENT_APPLICATION_ACTIVITY = 'STUDENT_APPLICATION_ACTIVITY';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    public function isRaw(): bool
    {
        return in_array($this, [
            self::STUDENTS, self::APPLICATIONS, self::INTERVIEWS, self::INTERNSHIP_ASSIGNMENTS,
            self::PPO_RECORDS, self::ATTENDANCE, self::INTERNSHIP_COMPLETIONS, self::WEEKLY_PROGRESS_REPORTS,
            self::INTERNSHIP_DOCUMENTS, self::PLACEMENT_DRIVES, self::COMPANIES,
        ], true);
    }

    public function isAnalytical(): bool
    {
        return ! $this->isRaw();
    }

    public function label(): string
    {
        return match ($this) {
            self::STUDENTS => 'Students',
            self::APPLICATIONS => 'Applications',
            self::INTERVIEWS => 'Interviews',
            self::INTERNSHIP_ASSIGNMENTS => 'Internship Assignments',
            self::PPO_RECORDS => 'PPO Records',
            self::ATTENDANCE => 'Attendance',
            self::INTERNSHIP_COMPLETIONS => 'Internship Completions',
            self::WEEKLY_PROGRESS_REPORTS => 'Weekly Progress Reports',
            self::INTERNSHIP_DOCUMENTS => 'Internship Documents',
            self::PLACEMENT_DRIVES => 'Placement Drives',
            self::COMPANIES => 'Companies',
            self::APPLICATION_SUMMARY => 'Application Summary',
            self::STATUS_SUMMARY => 'Status Summary',
            self::COMPANY_WISE_PLACEMENT_REPORT => 'Company-wise Placement Report',
            self::DEPARTMENT_WISE_PLACEMENT_REPORT => 'Department-wise Placement Report',
            self::BATCH_WISE_PLACEMENT_REPORT => 'Batch-wise Placement Report',
            self::STUDENT_APPLICATION_REPORT => 'Student Application Report',
            self::UNPLACED_STUDENT_REPORT => 'Unplaced Student Report',
            self::STUDENT_APPLICATION_ACTIVITY => 'Student Application Activity',
        };
    }
}
