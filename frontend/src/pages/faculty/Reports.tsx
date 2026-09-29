import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2 } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { EmptyState } from '@/components/ui/EmptyState'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { DataTable } from '@/components/ui/DataTable'
import { MetricBand } from '@/components/dashboard/MetricBand'
import { EmptyCalendarIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { InternshipCell, PersonCell } from '@/components/common/Cells'
import { useApiResource } from '@/hooks/useApiResource'
import { facultyService } from '@/services/facultyService'
import { parseApiError } from '@/utils/apiError'
import { formatDate } from '@/utils/dateFormat'
import type { AssignmentAttendanceSummary, AssignmentListItem } from '@/types/coordination'

interface AttendanceOverviewRow {
  assignment: AssignmentListItem
  summary: AssignmentAttendanceSummary
}

/** Attendance summaries are one request per assignment (the API has no bulk endpoint), so the overview is capped. */
const OVERVIEW_LIMIT = 20

/**
 * Faculty > Reports. Two real sources: GET /faculty/reports/summary
 * (the workload counts - pending reports/documents are computed by the
 * backend's FacultyReportService) and, for the attendance overview,
 * GET /faculty/internship-assignments/{id}/attendance per active
 * assignment - each row's numbers are the backend's own summary, not
 * recomputed from a page of records here.
 */
export function FacultyReportsPage() {
  const workload = useApiResource(() => facultyService.summary(), 'workload')

  const [rows, setRows] = useState<AttendanceOverviewRow[]>([])
  const [total, setTotal] = useState(0)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const loadOverview = useCallback(() => {
    setIsLoading(true)
    setError(null)
    facultyService
      .assignments({ status: 'ACTIVE', per_page: OVERVIEW_LIMIT })
      .then(async (page) => {
        const summaries = await Promise.all(page.items.map((assignment) => facultyService.assignmentAttendance(assignment.id).then((d) => ({ assignment, summary: d.summary }))))
        setRows(summaries)
        setTotal(page.meta.total)
      })
      .catch((err: unknown) => setError(parseApiError(err, 'Unable to load the attendance overview.').message))
      .finally(() => setIsLoading(false))
  }, [])

  useEffect(() => {
    loadOverview()
  }, [loadOverview])

  const w = workload.data
  const metrics = w
    ? [
        { label: 'Assigned students', value: w.assigned_students },
        { label: 'Active internships', value: w.active_students },
        { label: 'Reports to review', value: w.pending_weekly_reports },
        { label: 'Documents to review', value: w.pending_documents },
      ]
    : null

  const columns = [
    { key: 'student', header: 'Student', render: (r: AttendanceOverviewRow) => <PersonCell student={r.assignment.student} /> },
    { key: 'internship', header: 'Internship', render: (r: AttendanceOverviewRow) => <InternshipCell internship={r.assignment.internship} /> },
    {
      key: 'pct',
      header: 'Attendance',
      className: 'min-w-40',
      render: (r: AttendanceOverviewRow) =>
        r.summary.total_days === 0 ? (
          <span className="text-sm text-ink-faint">Nothing marked yet</span>
        ) : (
          <div className="flex items-center gap-3">
            <ProgressBar value={r.summary.attendance_percentage} tone={r.summary.attendance_percentage >= 75 ? 'success' : 'accent'} className="w-24" />
            <span className="text-sm font-medium tabular-nums text-ink">{Math.round(r.summary.attendance_percentage)}%</span>
          </div>
        ),
    },
    { key: 'p', header: 'Present', render: (r: AttendanceOverviewRow) => r.summary.present_days },
    { key: 'a', header: 'Absent', render: (r: AttendanceOverviewRow) => r.summary.absent_days },
    { key: 'l', header: 'Leave', render: (r: AttendanceOverviewRow) => r.summary.leave_days },
    { key: 'last', header: 'Last marked', render: (r: AttendanceOverviewRow) => formatDate(r.summary.last_attendance_date) },
  ]

  return (
    <div className="animate-fade-in space-y-8">
      <PageHeader title="Reports" description="A snapshot of your coordination workload and how your students are attending." />

      {workload.error ? (
        <div className="card" role="alert"><ErrorState description={workload.error} onRetry={workload.reload} /></div>
      ) : metrics ? (
        <MetricBand metrics={metrics} />
      ) : (
        <div className="card p-5" aria-busy="true"><LoadingSkeleton className="h-14 w-full" /></div>
      )}

      {w && (
        <section aria-label="Awaiting your review">
          {w.pending_weekly_reports + w.pending_documents === 0 ? (
            <p className="flex items-center gap-2 text-sm text-ink-soft">
              <CheckCircle2 className="h-4 w-4 text-success" />
              You're all caught up - nothing is waiting for your review.
            </p>
          ) : (
            <div className="flex flex-wrap gap-3">
              {w.pending_weekly_reports > 0 && (
                <Link to="/faculty/progress" className="btn-secondary">
                  {w.pending_weekly_reports} weekly {w.pending_weekly_reports === 1 ? 'report' : 'reports'} waiting
                </Link>
              )}
              {w.pending_documents > 0 && (
                <Link to="/faculty/documents" className="btn-secondary">
                  {w.pending_documents} {w.pending_documents === 1 ? 'document' : 'documents'} waiting
                </Link>
              )}
            </div>
          )}
        </section>
      )}

      <section>
        <SectionHeader title="Attendance overview" subtitle={total > OVERVIEW_LIMIT ? `Showing ${OVERVIEW_LIMIT} of ${total} active internships` : 'Active internships you coordinate'} />
        <div className="mt-4">
          {error ? (
            <div className="card" role="alert"><ErrorState description={error} onRetry={loadOverview} /></div>
          ) : isLoading ? (
            <div className="card divide-y divide-hairline" aria-busy="true">
              {[0, 1, 2].map((i) => (
                <div key={i} className="flex items-center gap-4 p-4"><LoadingSkeleton className="h-9 w-9 rounded-full" /><LoadingSkeleton className="h-4 w-1/3" /></div>
              ))}
            </div>
          ) : rows.length === 0 ? (
            <div className="card">
              <EmptyState illustration={<EmptyCalendarIllustration />} title="No active internships to report on" description="Attendance figures appear here once a student you coordinate starts an internship." />
            </div>
          ) : (
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(r) => r.assignment.id}
              renderMobileCard={(r) => (
                <div className="space-y-3">
                  <PersonCell student={r.assignment.student} subtitle={r.assignment.internship?.company?.name} />
                  {r.summary.total_days === 0 ? (
                    <p className="text-sm text-ink-faint">Nothing marked yet</p>
                  ) : (
                    <div className="flex items-center gap-3">
                      <ProgressBar value={r.summary.attendance_percentage} tone={r.summary.attendance_percentage >= 75 ? 'success' : 'accent'} className="flex-1" />
                      <span className="text-sm font-medium tabular-nums text-ink">{Math.round(r.summary.attendance_percentage)}%</span>
                    </div>
                  )}
                  <p className="text-xs text-ink-faint">{r.summary.present_days} present · {r.summary.absent_days} absent · {r.summary.leave_days} leave</p>
                </div>
              )}
            />
          )}
        </div>
      </section>
    </div>
  )
}
