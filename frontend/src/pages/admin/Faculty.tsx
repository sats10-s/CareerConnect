import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { SearchBar } from '@/components/ui/SearchBar'
import { MetricBand } from '@/components/dashboard/MetricBand'
import { EmptyBriefcaseIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { ListState } from '@/components/common/ListState'
import { FilterBar } from '@/components/common/FilterBar'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import type { FacultyWorkloadRow } from '@/types/admin'

/**
 * Placement Admin > Faculty. The backend has no faculty-management
 * endpoints (no list/create/edit for faculty accounts) - the one place it
 * lists faculty is the workload report, so this page is a read-only
 * workload directory built on GET /admin/reports/faculty, and says so.
 * The name search only narrows the list already returned; it is a
 * convenience, not an access control.
 */
export function AdminFacultyPage() {
  const { data, isLoading, error, reload } = useApiResource(() => adminService.reports.faculty(), 'faculty-directory')
  const [search, setSearch] = useState('')

  const all = data ?? []
  const rows = useMemo(() => {
    const q = search.trim().toLowerCase()
    return q ? all.filter((r) => r.faculty_name.toLowerCase().includes(q) || (r.department ?? '').toLowerCase().includes(q)) : all
  }, [all, search])
  const sum = (pick: (r: FacultyWorkloadRow) => number) => all.reduce((total, r) => total + pick(r), 0)

  const columns = [
    { key: 'name', header: 'Faculty', render: (r: FacultyWorkloadRow) => <div><p className="text-sm font-medium text-ink">{r.faculty_name}</p><p className="text-xs text-ink-faint">{r.department ?? 'No department'}</p></div> },
    { key: 'assigned', header: 'Assigned students', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.assigned_students}</span> },
    { key: 'active', header: 'Active internships', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.active_students}</span> },
    { key: 'reports', header: 'Reports to review', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.pending_weekly_reports}</span> },
    { key: 'docs', header: 'Documents to review', render: (r: FacultyWorkloadRow) => <span className="tabular-nums">{r.pending_documents}</span> },
    { key: 'open', header: '', render: (r: FacultyWorkloadRow) => <Link to={`/admin/internships?tab=assignments&faculty=${r.faculty_id}`} className="text-sm font-medium text-primary hover:underline">View assignments</Link> },
  ]

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="Faculty" description="Who is coordinating which students, and what is waiting for their review." />

      {data && all.length > 0 && (
        <MetricBand metrics={[
          { label: 'Faculty', value: all.length },
          { label: 'Assigned students', value: sum((r) => r.assigned_students) },
          { label: 'Reports to review', value: sum((r) => r.pending_weekly_reports) },
          { label: 'Documents to review', value: sum((r) => r.pending_documents) },
        ]} />
      )}

      <div>
        <FilterBar>
          <SearchBar value={search} onChange={setSearch} placeholder="Search by name or department" className="w-full sm:w-72" />
        </FilterBar>
        <ListState
          isLoading={isLoading}
          error={error}
          isEmpty={rows.length === 0}
          hasLoadedOnce={data !== null}
          onRetry={reload}
          empty={
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title={search.trim() ? 'No faculty match that search' : 'No faculty in the system yet'}
              description={search.trim() ? 'Try a different name or department.' : 'Faculty accounts appear here once they have been created.'}
            />
          }
        >
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.faculty_id}
            renderMobileCard={(r) => (
              <div className="space-y-2">
                <div>
                  <p className="text-sm font-medium text-ink">{r.faculty_name}</p>
                  <p className="text-xs text-ink-faint">{r.department ?? 'No department'}</p>
                </div>
                <p className="text-xs text-ink-soft">{r.assigned_students} students · {r.active_students} active · {r.pending_weekly_reports} reports · {r.pending_documents} documents waiting</p>
                <Link to={`/admin/internships?tab=assignments&faculty=${r.faculty_id}`} className="text-sm font-medium text-primary hover:underline">View assignments</Link>
              </div>
            )}
          />
        </ListState>
        <p className="mt-4 text-xs text-ink-faint">Faculty accounts are managed outside this portal - the server offers a workload report but no faculty add/edit endpoints, so this page is read-only.</p>
      </div>
    </div>
  )
}
