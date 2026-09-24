<?php

namespace App\Services\Reporting;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Models\Application;
use App\Models\Faculty;
use App\Models\Interview;
use App\Models\Student;

/**
 * Reports & Analytics module - the per-role `/reports/summary` (or
 * `/reports/dashboard`) payload for all four roles. Deliberately
 * separate from the pre-existing Api\Dashboard\* controllers (see
 * this module's final report for why those were left untouched) -
 * this is an additive, `/reports/...`-routed set of endpoints built on
 * top of the same Reporting services every other report in this
 * module uses.
 *
 * AUTHORIZATION - every method here takes the ALREADY-RESOLVED acting
 * Student/Faculty model (never a raw id from the request), mirroring
 * App\Http\Controllers\Api\Dashboard\FacultyDashboardController /
 * StudentDashboardController's own `$request->user()->student()/
 * faculty()->firstOrFail()` pattern - forStudent()/forFaculty() are
 * therefore structurally IDOR-safe: there is no student_id/faculty_id
 * request parameter that could be swapped for someone else's.
 * forAdmin() has no such per-actor scoping to begin with - an
 * institution-wide summary is exactly what every PLACEMENT_ADMIN/
 * SUPER_ADMIN is authorized to see (role:PLACEMENT_ADMIN,SUPER_ADMIN
 * middleware on its route).
 */
class DashboardAnalyticsService
{
    public function __construct(
        private readonly PlacementReportService $placementReportService,
        private readonly ApplicationReportService $applicationReportService,
        private readonly InternshipReportService $internshipReportService,
        private readonly AttendanceReportService $attendanceReportService,
        private readonly FacultyReportService $facultyReportService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forStudent(Student $student): array
    {
        $applicationQuery = Application::query()->where('student_id', $student->id);

        $byStatus = [];

        foreach (ApplicationStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $applicationQuery)->where('status', $status->value)->count();
        }

        $upcomingInterviews = Interview::query()
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->where('status', InterviewStatus::SCHEDULED->value)
            ->where('scheduled_at', '>=', now())
            ->count();

        $attendance = $this->attendanceReportService->perAssignmentSummary(['student_id' => $student->id]);

        return [
            'total_applications' => (clone $applicationQuery)->count(),
            'applications_by_status' => $byStatus,
            'upcoming_interviews' => $upcomingInterviews,
            'internship_assignments' => $attendance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forFaculty(Faculty $faculty): array
    {
        $workload = $this->facultyReportService->workload(['faculty_id' => $faculty->id]);

        return [
            'workload' => $workload[0] ?? [
                'faculty_id' => $faculty->id,
                'faculty_name' => $faculty->user?->name,
                'department' => $faculty->department?->name,
                'assigned_students' => 0,
                'active_students' => 0,
                'pending_weekly_reports' => 0,
                'pending_documents' => 0,
            ],
        ];
    }

    /**
     * The institution-wide summary shared by PLACEMENT_ADMIN and
     * SUPER_ADMIN (see this module's routing decision to reuse the
     * same Api\Admin\ReportController class under both `/admin/reports`
     * and `/super-admin/reports` rather than duplicating a controller).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forAdmin(array $filters): array
    {
        return [
            'placements' => $this->placementReportService->summary($filters),
            'application_funnel' => $this->applicationReportService->funnel($filters),
            'internships' => $this->internshipReportService->summary($filters),
            'recent_trends' => $this->applicationReportService->trends(array_merge($filters, ['granularity' => 'week'])),
        ];
    }
}
