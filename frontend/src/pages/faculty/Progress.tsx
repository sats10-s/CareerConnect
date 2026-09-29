import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { WeeklyReportDrawer } from '@/components/faculty/WeeklyReportDrawer'
import { useApiList } from '@/hooks/useApiList'
import { useFacultyAssignments } from '@/hooks/useFacultyAssignments'
import { facultyService } from '@/services/facultyService'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import { WEEKLY_REPORT_STATUS, optionsFor } from '@/utils/statusMeta'
import type { WeeklyReportRow } from '@/types/coordination'
import type { WeeklyReportStatus } from '@/types/weeklyReport'

/**
 * Faculty > Progress: students' weekly progress reports for the
 * assignments this faculty member coordinates
 * (GET /faculty/weekly-reports, server-scoped), with a detail drawer
 * that carries the review workflow (POST .../review).
 */
export function FacultyProgressPage() {
  const [params, setParams] = useSearchParams()
  const assignmentParam = params.get('assignment') ?? ''
  const [status, setStatus] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const assignments = useFacultyAssignments()

  const list = useApiList(facultyService.weeklyReports, {
    internship_assignment_id: assignmentParam ? Number(assignmentParam) : undefined,
    status: (status || undefined) as WeeklyReportStatus | undefined,
  })

  function setAssignment(value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set('assignment', value)
    else next.delete('assignment')
    setParams(next, { replace: true })
  }

  const hasFilters = Boolean(assignmentParam || status)
  const clearFilters = () => {
    setAssignment('')
    setStatus('')
  }

  const columns = [
    {
      key: 'week',
      header: 'Week',
      render: (row: WeeklyReportRow) => (
        <div>
          <p className="text-sm font-medium text-ink">Week {row.week_number}</p>
          <p className="text-xs text-ink-faint">{formatDate(row.week_start_date)} – {formatDate(row.week_end_date)}</p>
        </div>
      ),
    },
    { key: 'student', header: 'Student', render: (row: WeeklyReportRow) => <PersonCell student={row.student} /> },
    { key: 'internship', header: 'Internship', render: (row: WeeklyReportRow) => <InternshipCell internship={row.internship} /> },
    { key: 'status', header: 'Status', render: (row: WeeklyReportRow) => <MetaBadge map={WEEKLY_REPORT_STATUS} value={row.status} /> },
    { key: 'submitted', header: 'Submitted', render: (row: WeeklyReportRow) => <span className="text-sm text-ink-soft">{formatDateTime(row.student_submitted_at)}</span> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Progress" description="Weekly progress reports from your students. Open one to read it and review it." />

      <FilterBar>
        <FilterSelect
          label="Student"
          value={assignmentParam}
          onChange={setAssignment}
          options={(assignments.data ?? []).map((a) => ({ value: a.id, label: `${a.student?.name ?? 'Student'} — ${a.internship?.company?.name ?? ''}` }))}
          allLabel="All students"
        />
        <FilterSelect label="Report status" value={status} onChange={setStatus} options={optionsFor(WEEKLY_REPORT_STATUS)} allLabel="All statuses" />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
      </FilterBar>

      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={list.items.length === 0}
        hasLoadedOnce={list.meta !== null}
        onRetry={list.reload}
        empty={
          hasFilters ? (
            <EmptyState
              illustration={<EmptySearchIllustration />}
              title="No reports match these filters"
              description="Try another student or status, or clear the filters."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No weekly reports submitted yet"
              description="When a student you coordinate submits a weekly progress report, it will appear here for your review."
            />
          )
        }
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={(row) => setOpenId(row.id)}
          renderMobileCard={(row) => (
            <div className="space-y-3">
              <div className="flex items-start justify-between gap-3">
                <PersonCell student={row.student} subtitle={`Week ${row.week_number} · ${formatDate(row.week_start_date)}`} />
                <MetaBadge map={WEEKLY_REPORT_STATUS} value={row.status} />
              </div>
              <InternshipCell internship={row.internship} />
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <WeeklyReportDrawer reportId={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </div>
  )
}
