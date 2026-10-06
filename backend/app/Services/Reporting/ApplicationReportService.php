<?php

namespace App\Services\Reporting;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\Reporting\Concerns\AppliesReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reports & Analytics module - the application funnel and time-series
 * trend reports. Deliberately kept separate from
 * App\Services\Reporting\PlacementReportService, which answers
 * "how many students, historically, ever reached each stage";
 * ApplicationReportService::funnel() answers a different question -
 * "right now, how many applications currently sit in each status" -
 * and the two are never reconciled into a single number (see
 * PlacementReportService's docblock for the full reasoning).
 */
class ApplicationReportService
{
    use AppliesReportFilters;

    /**
     * The current-status distribution over the exact 8
     * App\Enums\ApplicationStatus values - a live snapshot of
     * Application.status (the current, single-value column), not the
     * historical ApplicationStatusHistory log. Every one of the 8
     * statuses is always present in the result, even at zero, so a
     * consumer never has to special-case a missing key.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array{applications: int, students: int}>
     */
    public function funnel(array $filters): array
    {
        $query = Application::query();
        $this->applyStudentRelationScope($query, $filters);
        $this->applyDateRange($query, 'applied_at', $filters);
        $this->applyCompanyScope($query, $filters);
        $this->applyApplicableType($query, $filters);

        $rows = $query
            ->selectRaw('status, COUNT(*) as application_count, COUNT(DISTINCT student_id) as student_count')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($r) => $r->status instanceof \BackedEnum ? $r->status->value : $r->status);

        $funnel = [];

        foreach (ApplicationStatus::cases() as $status) {
            $row = $rows->get($status->value);

            $funnel[$status->value] = [
                'applications' => $row ? (int) $row->application_count : 0,
                'students' => $row ? (int) $row->student_count : 0,
            ];
        }

        return $funnel;
    }

    /**
     * Application volume over time, bucketed by day/week/month
     * (filters['granularity'], validated upstream to one of those
     * three - defaults to 'day'). Rows are pulled with a single narrow
     * query (only applied_at is selected, never full Application
     * models) and bucketed in PHP with Carbon rather than a raw SQL
     * DATE_FORMAT/strftime expression, because the two database
     * drivers this app runs on (MySQL in production, SQLite in the
     * test suite) do not share a single date-truncation SQL dialect -
     * bucketing in PHP keeps this report correct and identical on
     * both without a driver-specific branch.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{period: string, applications: int}>
     */
    public function trends(array $filters): array
    {
        $granularity = in_array($filters['granularity'] ?? null, ['day', 'week', 'month'], true)
            ? $filters['granularity']
            : 'day';

        $query = Application::query();
        $this->applyStudentRelationScope($query, $filters);
        $this->applyDateRange($query, 'applied_at', $filters);

        $buckets = [];

        // No manual orderBy() here - chunkById() manages its own
        // ordering (by the model's key column) to paginate correctly;
        // an additional orderBy('applied_at') would break its
        // "id greater than the last chunk's max id" cursor logic. Rows
        // are bucketed into an associative array and ksort()'d below,
        // so insertion order from the chunk loop doesn't matter.
        $query->select('id', 'applied_at')
            ->chunkById(1000, function ($applications) use (&$buckets, $granularity) {
                foreach ($applications as $application) {
                    $appliedAt = CarbonImmutable::parse($application->applied_at);
                    $period = match ($granularity) {
                        'week' => $appliedAt->startOfWeek()->toDateString(),
                        'month' => $appliedAt->format('Y-m'),
                        default => $appliedAt->toDateString(),
                    };

                    $buckets[$period] = ($buckets[$period] ?? 0) + 1;
                }
            });

        ksort($buckets);

        return collect($buckets)
            ->map(fn ($count, $period) => ['period' => $period, 'applications' => $count])
            ->values()
            ->all();
    }

    /**
     * Every historical status transition matching the filters - the
     * raw material behind funnel()/trends() and useful on its own as
     * an activity log, mirroring
     * ExcelExportService::buildStudentApplicationActivity(). Kept here
     * (rather than duplicated) as the one place that queries
     * application_status_history for reporting purposes.
     *
     * @param  array<string, mixed>  $filters
     */
    public function statusChangeCount(array $filters): int
    {
        $query = DB::table('application_status_history')
            ->join('applications', 'applications.id', '=', 'application_status_history.application_id');

        // Inlined rather than via AppliesReportFilters::applyDateRange()
        // - that helper is typed against Illuminate\Database\Eloquent\Builder,
        // and DB::table() returns the separate Query\Builder, which is
        // not an instance of it.
        if (! empty($filters['date_from'])) {
            $query->whereDate('application_status_history.created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('application_status_history.created_at', '<=', $filters['date_to']);
        }

        return $query->count();
    }
}
