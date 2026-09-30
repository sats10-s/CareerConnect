import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { CalendarCheck, Pencil, Plus } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { IconButton } from '@/components/ui/IconButton'
import { EmptyCalendarIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { MetricBand } from '@/components/dashboard/MetricBand'
import { FilterBar, FilterDate, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { AttendanceFormModal } from '@/components/faculty/AttendanceFormModal'
import { useApiList } from '@/hooks/useApiList'
import { useApiResource } from '@/hooks/useApiResource'
import { useFacultyAssignments } from '@/hooks/useFacultyAssignments'
import { facultyService } from '@/services/facultyService'
import { formatDate, formatTimeOfDay } from '@/utils/dateFormat'
import { ATTENDANCE_STATUS, optionsFor } from '@/utils/statusMeta'
import type { AttendanceRecord } from '@/types/coordination'
import type { AttendanceStatus } from '@/types/attendance'

/**
 * Faculty > Attendance. Lists attendance across the faculty member's
 * own assignments (GET /faculty/attendance - server-scoped), marks new
 * days (POST /faculty/internship-assignments/{id}/attendance) and
 * corrects existing ones (PATCH /faculty/attendance/{id}). Choosing a
 * student (or arriving with ?assignment=ID from My Students) also
 * loads that assignment's summary strip, computed by the backend
 * (GET /faculty/internship-assignments/{id}/attendance) - not
 * re-derived from the visible page of rows.
 */
export function FacultyAttendancePage() {
  const [params, setParams] = useSearchParams()
  const assignmentParam = params.get('assignment') ?? ''
  const [status, setStatus] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [formMode, setFormMode] = useState<'create' | AttendanceRecord | null>(null)

  const assignments = useFacultyAssignments()
  const assignmentId = assignmentParam ? Number(assignmentParam) : undefined

  const list = useApiList(facultyService.attendance, {
    internship_assignment_id: assignmentId,
    status: (status || undefined) as AttendanceStatus | undefined,
    from_date: from || undefined,
    to_date: to || undefined,
  })

  const summary = useApiResource(
    () => facultyService.assignmentAttendance(assignmentId as number).then((d) => d.summary),
    assignmentId ?? null,
  )

  function setAssignment(value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set('assignment', value)
    else next.delete('assignment')
    setParams(next, { replace: true })
  }

  const hasFilters = Boolean(assignmentParam || status || from || to)
  const clearFilters = () => {
    setAssignment('')
    setStatus('')
    setFrom('')
    setTo('')
  }

  function afterSave() {
    list.reload()
    summary.reload()
  }

  const columns = [
    { key: 'date', header: 'Date', render: (row: AttendanceRecord) => <span className="whitespace-nowrap font-medium">{formatDate(row.attendance_date)}</span> },
    { key: 'student', header: 'Student', render: (row: AttendanceRecord) => <PersonCell student={row.student} /> },
    { key: 'internship', header: 'Internship', render: (row: AttendanceRecord) => <InternshipCell internship={row.internship} /> },
    { key: 'status', header: 'Status', render: (row: AttendanceRecord) => <MetaBadge map={ATTENDANCE_STATUS} value={row.status} /> },
    { key: 'in', header: 'Check-in', render: (row: AttendanceRecord) => formatTimeOfDay(row.check_in_time) },
    { key: 'out', header: 'Check-out', render: (row: AttendanceRecord) => formatTimeOfDay(row.check_out_time) },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      render: (row: AttendanceRecord) => (
        <IconButton
          icon={Pencil}
          label={`Correct attendance for ${row.student?.name ?? 'student'} on ${formatDate(row.attendance_date)}`}
          onClick={(event) => {
            event.stopPropagation()
            setFormMode(row)
          }}
        />
      ),
    },
  ]

  const summaryMetrics = summary.data
    ? [
        { label: 'Attendance', value: Math.round(summary.data.attendance_percentage), suffix: '%' },
        { label: 'Present', value: summary.data.present_days },
        { label: 'Absent', value: summary.data.absent_days },
        { label: 'On leave', value: summary.data.leave_days },
      ]
    : null

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Attendance"
        description="Mark and correct daily attendance for the students you coordinate."
        action={
          <Button onClick={() => setFormMode('create')}>
            <Plus className="h-4 w-4" />
            Mark attendance
          </Button>
        }
      />

      {summaryMetrics && (
        <div className="mb-4" data-testid="attendance-summary">
          <MetricBand metrics={summaryMetrics} />
        </div>
      )}

      <FilterBar>
        <FilterSelect
          label="Student"
          value={assignmentParam}
          onChange={setAssignment}
          options={(assignments.data ?? []).map((a) => ({ value: a.id, label: `${a.student?.name ?? 'Student'} — ${a.internship?.company?.name ?? ''}` }))}
          allLabel="All students"
        />
        <FilterSelect label="Attendance status" value={status} onChange={setStatus} options={optionsFor(ATTENDANCE_STATUS)} allLabel="All statuses" />
        <FilterDate label="From" value={from} onChange={setFrom} />
        <FilterDate label="To" value={to} onChange={setTo} />
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
              title="No attendance matches these filters"
              description="Try another date range or status, or clear the filters."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyCalendarIllustration />}
              title="No attendance recorded yet"
              description="Once you mark a student present, absent or on leave, the day shows up here."
              action={
                <Button onClick={() => setFormMode('create')}>
                  <CalendarCheck className="h-4 w-4" />
                  Mark attendance
                </Button>
              }
            />
          )
        }
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          renderMobileCard={(row) => (
            <div className="space-y-3">
              <div className="flex items-start justify-between gap-3">
                <PersonCell student={row.student} subtitle={formatDate(row.attendance_date)} />
                <MetaBadge map={ATTENDANCE_STATUS} value={row.status} />
              </div>
              <div className="flex items-center justify-between gap-3">
                <p className="text-xs text-ink-faint">
                  {row.status === 'PRESENT' && row.check_in_time ? `${formatTimeOfDay(row.check_in_time)} – ${formatTimeOfDay(row.check_out_time)}` : row.internship?.company?.name}
                </p>
                <Button variant="secondary" onClick={() => setFormMode(row)}>
                  <Pencil className="h-4 w-4" />
                  Correct
                </Button>
              </div>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <AttendanceFormModal
        open={formMode !== null}
        mode={
          formMode === null || formMode === 'create'
            ? { type: 'create', assignments: assignments.data ?? [], defaultAssignmentId: assignmentId }
            : { type: 'edit', record: formMode }
        }
        onClose={() => setFormMode(null)}
        onSaved={afterSave}
      />
    </div>
  )
}
