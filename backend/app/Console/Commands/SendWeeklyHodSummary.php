<?php

namespace App\Console\Commands;

use App\Enums\ExcelExportType;
use App\Mail\WeeklyHodSummary;
use App\Models\Application;
use App\Models\Department;
use App\Services\ExcelExportService;
use App\Services\Reporting\PlacementReportService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Emails every department that has an HOD email its weekly placement
 * summary. Scheduled for Monday mornings in routes/console.php; does
 * nothing while the "weekly_hod_summary" setting is off unless --force
 * is given (handy for a test send to one department with --department).
 */
class SendWeeklyHodSummary extends Command
{
    protected $signature = 'reports:weekly-hod-summary {--department= : Only this department code} {--force : Send even if the setting is off}';

    protected $description = 'Email each HOD their department\'s weekly placement summary';

    public function handle(SettingsService $settings, PlacementReportService $placements, ExcelExportService $exports): int
    {
        if (! $settings->get('weekly_hod_summary') && ! $this->option('force')) {
            $this->info('Weekly HOD summary is turned off in Settings.');

            return self::SUCCESS;
        }

        $departments = Department::query()
            ->whereNotNull('hod_email')
            ->when($this->option('department'), fn ($q, $code) => $q->where('code', strtoupper($code)))
            ->orderBy('name')
            ->get();

        $since = now()->subWeek();
        $weekLabel = 'week ending '.now()->format('j M Y');
        $institution = (string) $settings->get('institution_name');

        foreach ($departments as $department) {
            $filters = ['department_id' => $department->id];
            $inDepartment = fn ($q) => $q->where('department_id', $department->id);

            Mail::to($department->hod_email)->send(new WeeklyHodSummary(
                $department,
                $institution,
                $weekLabel,
                $placements->classBreakdown($filters),
                Application::whereHas('student', $inDepartment)->where('status', 'SELECTED')->where('selected_at', '>=', $since)->count(),
                Application::whereHas('student', $inDepartment)->where('applied_at', '>=', $since)->count(),
                [
                    "{$department->code}_section_wise_placement.xlsx" => $this->xlsx($exports, ExcelExportType::SECTION_WISE_PLACEMENT_REPORT, $filters),
                    "{$department->code}_unplaced_students.xlsx" => $this->xlsx($exports, ExcelExportType::UNPLACED_STUDENT_REPORT, $filters),
                ],
            ));

            $this->line("Sent {$department->code} summary to {$department->hod_email}");
        }

        if ($departments->isEmpty()) {
            $this->info('No department has an HOD email set.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function xlsx(ExcelExportService $exports, ExcelExportType $type, array $filters): string
    {
        ob_start();
        (new Xlsx($exports->build($type, $filters)))->save('php://output');

        return (string) ob_get_clean();
    }
}
