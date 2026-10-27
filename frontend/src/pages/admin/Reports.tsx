import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Download } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { MetricBand } from '@/components/dashboard/MetricBand'
import { EmptyBriefcaseIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterDate, FilterSelect } from '@/components/common/FilterBar'
import { ReportPanel } from '@/components/admin/reports/ReportPanel'
import { FunnelChart } from '@/components/admin/reports/FunnelChart'
import { TrendChart } from '@/components/admin/reports/TrendChart'
import { useApiResource } from '@/hooks/useApiResource'
import { useCompanyOptions } from '@/hooks/useAdminOptions'
import { adminService } from '@/services/adminService'
import { metaService } from '@/services/metaService'
import { formatPercent } from '@/utils/numberFormat'
import { ASSIGNMENT_STATUS } from '@/utils/statusMeta'
import type { AttendanceReportRow, BreakdownRow, FacultyWorkloadRow, ReportFilters } from '@/types/admin'

/** A select that always has a value (no "all" option) - for sort order, granularity and threshold. */
function ChoiceSelect({ label, value, onChange, options }: { label: string; value: string | number; onChange: (value: string) => void; options: { value: string | number; label: string }[] }) {
  return (
    <div className="w-44 shrink-0">
      <Select aria-label={label} value={String(value)} onChange={(event) => onChange(event.target.value)}>
        {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
      </Select>
    </div>
  )
}

type Dimension = 'company' | 'department' | 'batch'
const DIMENSIONS = [
  { key: 'company', label: 'By company' },
  { key: 'department', label: 'By department' },
  { key: 'batch', label: 'By batch' },
]
const SORTS = [
  { value: 'total_applications', label: 'Most applications' },
  { value: 'students_selected', label: 'Most selected' },
  { value: 'selection_rate', label: 'Highest selection rate' },
]
const GRANULARITY = [
  { value: 'day', label: 'Per day' },
  { value: 'week', label: 'Per week' },
  { value: 'month', label: 'Per month' },
]
const THRESHOLD_STEPS = [50, 60, 70, 75, 80, 90]

/**
 * Placement Admin > Reports: the institution-wide analytics from
 * /admin/reports/* (dashboard, placements breakdown, trends, attendance,
 * faculty). The numbers are all computed by the backend - nothing is
 * derived or filtered here. Each section loads independently, so one
 * failing report doesn't hide the rest. Department and batch options come
 * from /meta; company options from GET /admin/companies.
 */
export function AdminReportsPage() {
  const [department, setDepartment] = useState('')
  const [batch, setBatch] = useState('')
  const [company, setCompany] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [dimension, setDimension] = useState<Dimension>('company')
  const [sort, setSort] = useState('total_applications')
  const [granularity, setGranularity] = useState<'day' | 'week' | 'month'>('day')
  // null = use the institution default (Super Admin > Settings), which the API applies and echoes back.
  const [pickedThreshold, setThreshold] = useState<number | null>(null)

  const dateRangeInvalid = Boolean(from && to && to < from)
  const filters = useMemo<ReportFilters>(() => {
    const f: ReportFilters = {}
    if (department) f.department_id = Number(department)
    if (batch) f.batch_id = Number(batch)
    if (company) f.company_id = Number(company)
    if (from) f.date_from = from
    if (to && !dateRangeInvalid) f.date_to = to
    return f
  }, [department, batch, company, from, to, dateRangeInvalid])
  const fkey = JSON.stringify(filters)
  const hasFilters = Boolean(department || batch || company || from || to)

  const companies = useCompanyOptions()
  const departments = useApiResource(() => metaService.departments(), 'dept-options')
  const batches = useApiResource(() => metaService.batches(), 'batch-options')

  const dashboard = useApiResource(() => adminService.reports.dashboard(filters), `dash:${fkey}`)
  const breakdown = useApiResource(() => adminService.reports.placements({ ...filters, dimension, sort, direction: 'desc' }), `bd:${dimension}:${sort}:${fkey}`)
  const trends = useApiResource(() => adminService.reports.trends({ ...filters, granularity }), `tr:${granularity}:${fkey}`)
  const attendance = useApiResource(
    () => adminService.reports.attendance(pickedThreshold === null ? filters : { ...filters, threshold: pickedThreshold }),
    `at:${pickedThreshold ?? 'default'}:${fkey}`,
  )
  const threshold = pickedThreshold ?? attendance.data?.below_threshold.threshold ?? 75
  const thresholdOptions = [...new Set([...THRESHOLD_STEPS, threshold])].sort((a, b) => a - b).map((n) => ({ value: n, label: `Below ${n}%` }))
  const faculty = useApiResource(() => adminService.reports.faculty(filters), `fa:${fkey}`)

  function clear() {
    setDepartment(''); setBatch(''); setCompany(''); setFrom(''); setTo('')
  }

  const p = dashboard.data?.placements
  const f = dashboard.data?.internships
  const noApplications = p !== undefined && p.total_applications === 0

  const breakdownColumns = [
    { key: 'label', header: dimension === 'company' ? 'Company' : dimension === 'department' ? 'Department' : 'Batch', render: (r: BreakdownRow) => <span className="font-medium text-ink">{r.label}</span> },
    { key: 'apps', header: 'Applications', render: (r: BreakdownRow) => <span className="tabular-nums">{r.total_applications}</span> },
    { key: 'students', header: 'Students', render: (r: BreakdownRow) => <span className="tabular-nums">{r.distinct_students}</span> },
    { key: 'sel', header: 'Selected', render: (r: BreakdownRow) => <span className="tabular-nums">{r.students_selected}</span> },
    { key: 'rate', header: 'Selection rate', render: (r: BreakdownRow) => <span className="tabular-nums">{formatPercent(r.selection_rate)}</span> },
  ]

  const attendanceRows = attendance.data?.below_threshold.rows ?? []
  const attendanceColumns = [
    { key: 'student', header: 'Student', render: (r: AttendanceReportRow) => <div><p className="text-sm font-medium text-ink">{r.student_name}</p><p className="text-xs text-ink-faint">{r.roll_number}</p></div> },
    { key: 'dept', header: 'Department', render: (r: AttendanceReportRow) => <span className="text-sm text-ink-soft">{[r.department, r.batch].filter(Boolean).join(' · ') || '—'}</span> },
    { key: 'faculty', header: 'Coordinator', render: (r: AttendanceReportRow) => <span className="text-sm text-ink-soft">{r.faculty_name ?? 'Unassigned'}</span> },
    { key: 'days', header: 'Present / Absent / Leave', render: (r: AttendanceReportRow) => <span className="text-sm tabular-nums">{r.present_days} / {r.absent_days} / {r.leave_days}</span> },
    {
      key: 'pct', header: 'Attendance', className: 'min-w-40',
      render: (r: AttendanceReportRow) => (
        <div className="flex items-center gap-3">
          <ProgressBar label="Attendance" value={r.attendance_percentage} tone="accent" className="w-24" />
          <span className="text-sm font-medium tabular-nums text-ink">{formatPercent(r.attendance_percentage)}</span>
        </div>
      ),
    },
  ]

  const facultyRows = faculty.data ?? []
  const facultyColumns = [
    { key: 'name', header: 'Faculty', render: (r: FacultyWorkloadRow) => <div><p className="text-sm font-medium text-ink">{r.faculty_name}</p><p className="text-xs text-ink-faint">{r.department ?? 'No department'}</p></div> },
    { key: 'assigned', header: 'Assigned students', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.assigned_students}</span> },
    { key: 'active', header: 'Active internships', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.active_students}</span> },
    { key: 'reports', header: 'Reports to review', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.pending_weekly_reports}</span> },
    { key: 'docs', header: 'Documents to review', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.pending_documents}</span> },
  ]

  return (
    <div className="animate-fade-in space-y-10">
      <PageHeader
        title="Reports"
        description="Institution-wide placement, internship and attendance analytics, computed by the server from live records."
        action={
          <Link to="/admin/excel-sync?tab=export" className="btn-secondary">
            <Download className="h-4 w-4" />
            Export as Excel
          </Link>
        }
      />

      <div>
        <FilterBar>
          <FilterSelect label="Department" value={department} onChange={setDepartment} options={(departments.data ?? []).map((d) => ({ value: d.id, label: d.name }))} allLabel="All departments" />
          <FilterSelect label="Batch" value={batch} onChange={setBatch} options={(batches.data ?? []).map((b) => ({ value: b.id, label: b.name }))} allLabel="All batches" />
          <FilterSelect label="Company" value={company} onChange={setCompany} options={(companies.data ?? []).map((c) => ({ value: c.id, label: c.name }))} allLabel="All companies" />
          <FilterDate label="From" value={from} onChange={setFrom} />
          <FilterDate label="To" value={to} onChange={setTo} />
          {hasFilters && <Button variant="secondary" onClick={clear}>Clear filters</Button>}
        </FilterBar>
        {dateRangeInvalid && <p role="alert" className="-mt-2 text-sm text-error">The end date must be on or after the start date - the end date is ignored until it is.</p>}
      </div>

      <ReportPanel title="Placement overview" subtitle="Students and applications in the selected scope" isLoading={dashboard.isLoading} hasData={Boolean(p)} error={dashboard.error} onRetry={dashboard.reload}>
        {p && (
          <div className="space-y-4">
            <MetricBand metrics={[
              { label: 'Students', value: p.total_students },
              { label: 'Applied', value: p.unique_applicants },
              { label: 'Selected', value: p.students_selected },
              { label: 'Explicitly placed', value: p.students_placed },
              { label: 'Not placed', value: p.students_unplaced },
              { label: 'Unconfirmed', value: p.students_unconfirmed },
            ]} />
            <MetricBand metrics={[
              { label: 'Applications', value: p.total_applications },
              { label: 'Shortlisted', value: p.students_shortlisted },
              { label: 'Interviewed', value: p.students_interviewed },
              { label: 'Selection rate', value: Math.round(p.selection_rate), suffix: '%' },
            ]} />
          </div>
        )}
      </ReportPanel>

      <ReportPanel title="Application funnel" subtitle="Where applications currently stand" isLoading={dashboard.isLoading} hasData={Boolean(dashboard.data)} error={dashboard.error} onRetry={dashboard.reload}>
        {dashboard.data && (noApplications ? (
          <div className="card"><EmptyState illustration={<EmptyBriefcaseIllustration />} title="No applications in this scope" description={hasFilters ? 'Nothing matches these filters. Widen the date range or clear a filter.' : 'The funnel fills in once students start applying to jobs and internships.'} /></div>
        ) : <FunnelChart funnel={dashboard.data.application_funnel} />)}
      </ReportPanel>

      <ReportPanel
        title="Placement breakdown"
        subtitle="Applications and selections grouped by one dimension"
        isLoading={breakdown.isLoading}
        hasData={Boolean(breakdown.data)}
        error={breakdown.error}
        onRetry={breakdown.reload}
        action={<ChoiceSelect label="Sort breakdown" value={sort} onChange={setSort} options={SORTS} />}
      >
        <div className="mb-4"><Tabs items={DIMENSIONS} active={dimension} onChange={(k) => setDimension(k as Dimension)} /></div>
        {breakdown.data && (breakdown.data.breakdown.rows.length === 0 ? (
          <div className="card"><EmptyState illustration={<EmptyBriefcaseIllustration />} title={`No ${dimension} data yet`} description={hasFilters ? 'No applications match these filters.' : 'Rows appear here once applications exist.'} /></div>
        ) : (
          <DataTable
            columns={breakdownColumns}
            rows={breakdown.data.breakdown.rows}
            rowKey={(r) => `${r.id}-${r.label}`}
            renderMobileCard={(r) => (
              <div className="space-y-1">
                <p className="text-sm font-medium text-ink">{r.label}</p>
                <p className="text-xs text-ink-faint">{r.total_applications} applications · {r.distinct_students} students · {r.students_selected} selected · {formatPercent(r.selection_rate)}</p>
              </div>
            )}
          />
        ))}
      </ReportPanel>

      <ReportPanel
        title="Application trend"
        subtitle="When students applied"
        isLoading={trends.isLoading}
        hasData={Boolean(trends.data)}
        error={trends.error}
        onRetry={trends.reload}
        action={<ChoiceSelect label="Trend granularity" value={granularity} onChange={(v) => setGranularity(v as 'day' | 'week' | 'month')} options={GRANULARITY} />}
      >
        {trends.data && (trends.data.points.length === 0 ? (
          <div className="card"><EmptyState illustration={<EmptyBriefcaseIllustration />} title="No applications over this period" description={hasFilters ? 'Try a wider date range.' : 'The chart appears once applications are submitted.'} /></div>
        ) : <TrendChart points={trends.data.points} granularity={trends.data.granularity} />)}
      </ReportPanel>

      <ReportPanel title="Internships" subtitle="Assignments, completion and pre-placement offers" isLoading={dashboard.isLoading} hasData={Boolean(f)} error={dashboard.error} onRetry={dashboard.reload}>
        {f && (f.total_assignments === 0 ? (
          <div className="card"><EmptyState illustration={<EmptyBriefcaseIllustration />} title="No internship assignments in this scope" description="Assign a coordinator to a selected student from Internships to start tracking them here." action={<Link to="/admin/internships?tab=assignments" className="btn-secondary">Go to assignments</Link>} /></div>
        ) : (
          <div className="space-y-4">
            <MetricBand metrics={[
              { label: 'Assignments', value: f.total_assignments },
              { label: 'Completed', value: f.completions_outcome_completed },
              { label: 'Completion rate', value: Math.round(f.completion_rate), suffix: '%' },
              { label: 'PPOs offered', value: f.ppo_offered },
            ]} />
            <div className="card flex flex-wrap gap-x-6 gap-y-2 px-5 py-4 text-sm text-ink-soft">
              {(Object.keys(f.assignments_by_status) as (keyof typeof f.assignments_by_status)[]).map((status) => (
                <span key={status}>{ASSIGNMENT_STATUS[status]?.label ?? status}: <span className="font-medium tabular-nums text-ink">{f.assignments_by_status[status]}</span></span>
              ))}
              <span>PPO accepted: <span className="font-medium tabular-nums text-ink">{f.ppo_accepted}</span></span>
              <span>PPO declined: <span className="font-medium tabular-nums text-ink">{f.ppo_declined}</span></span>
              <span>PPO offer rate: <span className="font-medium tabular-nums text-ink">{formatPercent(f.ppo_offer_rate)}</span></span>
            </div>
          </div>
        ))}
      </ReportPanel>

      <ReportPanel
        title="Attendance below threshold"
        subtitle="Interns whose marked-day attendance is under the chosen level"
        isLoading={attendance.isLoading}
        hasData={Boolean(attendance.data)}
        error={attendance.error}
        onRetry={attendance.reload}
        action={<ChoiceSelect label="Attendance threshold" value={threshold} onChange={(v) => setThreshold(Number(v))} options={thresholdOptions} />}
      >
        {attendance.data && (attendanceRows.length === 0 ? (
          <div className="card"><EmptyState illustration={<EmptyBriefcaseIllustration />} title={attendance.data.items.length === 0 ? 'No attendance has been marked yet' : `Nobody is below ${threshold}%`} description={attendance.data.items.length === 0 ? 'Once faculty mark attendance, students who fall behind show up here.' : `All ${attendance.data.items.length} tracked interns are at or above ${threshold}%.`} /></div>
        ) : (
          <DataTable
            columns={attendanceColumns}
            rows={attendanceRows}
            rowKey={(r) => r.internship_assignment_id}
            renderMobileCard={(r) => (
              <div className="space-y-2">
                <div className="flex items-start justify-between gap-3">
                  <div><p className="text-sm font-medium text-ink">{r.student_name}</p><p className="text-xs text-ink-faint">{r.roll_number} · {r.faculty_name ?? 'Unassigned'}</p></div>
                  <span className="text-sm font-medium tabular-nums text-ink">{formatPercent(r.attendance_percentage)}</span>
                </div>
                <ProgressBar label="Attendance" value={r.attendance_percentage} tone="accent" className="w-full" />
              </div>
            )}
          />
        ))}
      </ReportPanel>

      <ReportPanel
        title="Faculty workload"
        subtitle="Coordination load and reviews waiting"
        isLoading={faculty.isLoading}
        hasData={Boolean(faculty.data)}
        error={faculty.error}
        onRetry={faculty.reload}
        action={<Link to="/admin/faculty" className="text-sm font-medium text-primary hover:underline">Open faculty</Link>}
      >
        {faculty.data && (facultyRows.length === 0 ? (
          <div className="card"><EmptyState illustration={<EmptyBriefcaseIllustration />} title="No faculty to report on" description="Faculty appear here once they exist in the system." /></div>
        ) : (
          <DataTable
            columns={facultyColumns}
            rows={facultyRows}
            rowKey={(r) => r.faculty_id}
            renderMobileCard={(r) => (
              <div className="space-y-1">
                <p className="text-sm font-medium text-ink">{r.faculty_name}</p>
                <p className="text-xs text-ink-faint">{r.assigned_students} student{r.assigned_students === 1 ? '' : 's'} · {r.active_students} active · {r.pending_weekly_reports} report{r.pending_weekly_reports === 1 ? '' : 's'} · {r.pending_documents} document{r.pending_documents === 1 ? '' : 's'} waiting</p>
              </div>
            )}
          />
        ))}
      </ReportPanel>
    </div>
  )
}
