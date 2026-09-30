import { ClipboardList, FolderOpen, GraduationCap, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import { StatCard } from '@/components/ui/StatCard'
import { PageHeader } from '@/components/ui/PageHeader'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { useApiResource } from '@/hooks/useApiResource'
import { useAuth } from '@/hooks/useAuth'
import { facultyService } from '@/services/facultyService'
import { ASSIGNMENT_STATUS } from '@/utils/statusMeta'
import type { FacultyStudentItem } from '@/types/coordination'

/**
 * Faculty dashboard. The numbers come from GET /faculty/reports/summary
 * (the coordinator workload the backend computes from real assignments,
 * reports and documents) and the list from GET /faculty/students - the
 * same sources as My Students and Reports, so the dashboard can never
 * disagree with them. (GET /faculty/dashboard still returns the original
 * foundation stats - placeholder zeros and an institution-wide postings
 * count - so it isn't used here.)
 */
export function FacultyDashboardPage() {
  const { user } = useAuth()
  const { data, isLoading, error, reload } = useApiResource(
    () => Promise.all([facultyService.summary(), facultyService.students({ per_page: 5 })]).then(([workload, students]) => ({ workload, students })),
    'faculty-dashboard',
  )

  if (isLoading && !data) return <DashboardLoading />
  if (error || !data) return <DashboardError message={error} onRetry={reload} />

  const { workload, students } = data
  const rows = students.items

  return (
    <div className="animate-fade-in space-y-8">
      <PageHeader title={`Welcome back, ${user?.name ?? ''}`.trim()} description="The students you coordinate and what is waiting for your review." />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Assigned students" value={workload.assigned_students} icon={Users} tone="navy" />
        <StatCard label="Active internships" value={workload.active_students} icon={GraduationCap} tone="green" />
        <StatCard label="Reports to review" value={workload.pending_weekly_reports} icon={ClipboardList} tone="amber" />
        <StatCard label="Documents to review" value={workload.pending_documents} icon={FolderOpen} tone="amber" />
      </div>

      {workload.pending_weekly_reports + workload.pending_documents > 0 && (
        <div className="flex flex-wrap gap-3">
          {workload.pending_weekly_reports > 0 && <Link to="/faculty/progress" className="btn-secondary">Review weekly reports</Link>}
          {workload.pending_documents > 0 && <Link to="/faculty/documents" className="btn-secondary">Review documents</Link>}
        </div>
      )}

      <section>
        <SectionHeader
          title="My students"
          subtitle={students.meta.total > rows.length ? `Showing ${rows.length} of ${students.meta.total}` : 'Students assigned to you as internship coordinator'}
          action={<Link to="/faculty/students" className="text-sm font-medium text-primary hover:underline">View all</Link>}
        />
        <div className="mt-4">
          {rows.length === 0 ? (
            <div className="card"><EmptyState icon={Users} title="No students assigned yet" description="Students appear here once the placement office assigns you as their internship coordinator." /></div>
          ) : (
            <DataTable
              columns={[
                { key: 'student', header: 'Student', render: (s: FacultyStudentItem) => <PersonCell student={s} /> },
                { key: 'dept', header: 'Department', render: (s: FacultyStudentItem) => <span className="text-ink-soft">{s.department ?? '—'}</span> },
                { key: 'internship', header: 'Internship', render: (s: FacultyStudentItem) => <InternshipCell internship={s.assignment?.internship} /> },
                { key: 'status', header: 'Status', render: (s: FacultyStudentItem) => <MetaBadge map={ASSIGNMENT_STATUS} value={s.assignment?.status} /> },
              ]}
              rows={rows}
              rowKey={(s) => s.id}
              renderMobileCard={(s) => (
                <div className="flex items-start justify-between gap-3">
                  <PersonCell student={s} subtitle={s.assignment?.internship?.title ?? s.department} />
                  <MetaBadge map={ASSIGNMENT_STATUS} value={s.assignment?.status} />
                </div>
              )}
            />
          )}
        </div>
      </section>
    </div>
  )
}
