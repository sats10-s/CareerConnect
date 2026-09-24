<?php

namespace App\Services\Reporting\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Reports & Analytics module - the shared, schema-backed filter
 * vocabulary every Reporting service applies. Deliberately mirrors
 * App\Services\ExcelExportService's own applyStudentScopeFilters() /
 * applyApplicationEntityFilters() / applyDateRange() helpers exactly
 * (same option names, same semantics) rather than inventing a second,
 * slightly-different filter language for the API - this is one half
 * of the "API and Excel reports share one calculation path" rule (the
 * other half is ExcelExportService delegating its own aggregation
 * queries to these Reporting services instead of recomputing them).
 *
 * Every filter is OPTIONAL - an absent/empty value is a no-op, never a
 * rejection (a report with no filters applied returns the full
 * unfiltered dataset), matching every list endpoint and
 * ExcelExportFilterRequest's own tolerant convention.
 *
 * Recognised filter keys:
 *  - department_id, batch_id, year: student-scoping (a Student's own
 *    columns, or reached via whereHas('student', ...) / a join on
 *    students.department_id|batch_id|year for a query that is not
 *    itself Student::query()).
 *  - company_id: an opportunity's owning company (job posting or
 *    internship), only meaningful once a company/companies join is
 *    already present in the query.
 *  - student_id: narrows to a single student - used by the per-role
 *    dashboard summaries (App\Services\Reporting\DashboardAnalyticsService)
 *    to scope a student's own data, never accepted from client input
 *    on an institution-wide report endpoint.
 *  - date_from / date_to: an inclusive whereDate() range against
 *    whichever column the caller names (applied_at, attendance_date,
 *    created_at, ...) - never assumed to be a single fixed column,
 *    since every report filters a different date column.
 */
trait AppliesReportFilters
{
    /**
     * For a query whose base model already has department_id/batch_id/
     * year columns of its own (Student::query()).
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyStudentOwnScope(Builder $query, array $filters): void
    {
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['batch_id'])) {
            $query->where('batch_id', $filters['batch_id']);
        }

        if (! empty($filters['year'])) {
            $query->where('year', $filters['year']);
        }

        if (! empty($filters['student_id'])) {
            $query->where('id', $filters['student_id']);
        }
    }

    /**
     * For a query one relation-hop away from Student (Application::query(),
     * etc.) - applies the same department_id/batch_id/year/student_id
     * filters via whereHas() against the named relation.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyStudentRelationScope(Builder $query, array $filters, string $relation = 'student'): void
    {
        if (! empty($filters['department_id'])) {
            $query->whereHas($relation, fn (Builder $q) => $q->where('department_id', $filters['department_id']));
        }

        if (! empty($filters['batch_id'])) {
            $query->whereHas($relation, fn (Builder $q) => $q->where('batch_id', $filters['batch_id']));
        }

        if (! empty($filters['year'])) {
            $query->whereHas($relation, fn (Builder $q) => $q->where('year', $filters['year']));
        }

        if (! empty($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange(Builder $query, string $column, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate($column, '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate($column, '<=', $filters['date_to']);
        }
    }

    /**
     * Consistent pagination metadata for the (few) report endpoints
     * large enough to warrant paging - every other report returns its
     * full aggregate/breakdown in one response, matching how this
     * codebase's list endpoints already shape a paginated payload
     * (`data.items` + `data.meta`, e.g. Api\Admin\AnnouncementController).
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed>  $paginator
     * @return array{items: array<int, mixed>, meta: array<string, int>}
     */
    private function paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'items' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
