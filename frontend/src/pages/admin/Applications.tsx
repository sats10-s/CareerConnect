import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { ApplicationBadge, InternshipCell, PersonCell } from '@/components/common/Cells'
import { ApplicationDrawer } from '@/components/admin/ApplicationDrawer'
import { useCompanyOptions } from '@/hooks/useAdminOptions'
import { useApiList } from '@/hooks/useApiList'
import { adminService } from '@/services/adminService'
import { formatDate } from '@/utils/dateFormat'
import { labelForApplicationStatus } from '@/components/ui/StatusBadge'
import type { AdminApplicationRow } from '@/types/admin'
import type { ApplicationStatus } from '@/types/application'

const STATUSES = ['APPLIED', 'UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'INTERVIEW', 'SELECTED', 'REJECTED', 'WITHDRAWN']

/**
 * Placement Admin > Applications - /api/admin/applications: every
 * application across jobs and internships, with the admin-only actions
 * (status changes, scheduling an interview) in the detail drawer.
 * The status buttons offered follow ApplicationStatus::canTransitionTo().
 */
export function AdminApplicationsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const companies = useCompanyOptions()

  const list = useApiList(adminService.applications.list, {
    search: search.trim() || undefined,
    status: (status || undefined) as ApplicationStatus | undefined,
    type: (type || undefined) as 'job' | 'internship' | undefined,
    company_id: companyId ? Number(companyId) : undefined,
  })

  const hasFilters = Boolean(search.trim() || status || type || companyId)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
    setType('')
    setCompanyId('')
  }

  const columns = [
    { key: 'student', header: 'Student', render: (row: AdminApplicationRow) => <PersonCell student={row.student} subtitle={row.student ? `${row.student.roll_number} · ${row.student.department ?? ''}` : undefined} /> },
    { key: 'opportunity', header: 'Applied to', render: (row: AdminApplicationRow) => <InternshipCell internship={row.opportunity} /> },
    { key: 'type', header: 'Type', render: (row: AdminApplicationRow) => (row.opportunity?.type === 'internship' ? 'Internship' : 'Job') },
    { key: 'status', header: 'Status', render: (row: AdminApplicationRow) => <ApplicationBadge status={row.status} /> },
    { key: 'applied', header: 'Applied', render: (row: AdminApplicationRow) => <span className="text-sm text-ink-soft">{formatDate(row.applied_at)}</span> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Applications" description="Every student application across jobs and internships. Open one to review it, change its status or schedule an interview." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by student, roll number or role" className="w-full sm:w-72" />
        <FilterSelect label="Application status" value={status} onChange={setStatus} options={STATUSES.map((s) => ({ value: s, label: labelForApplicationStatus(s) }))} allLabel="All statuses" />
        <FilterSelect label="Type" value={type} onChange={setType} options={[{ value: 'job', label: 'Jobs' }, { value: 'internship', label: 'Internships' }]} allLabel="Jobs and internships" />
        <FilterSelect label="Company" value={companyId} onChange={setCompanyId} options={(companies.data ?? []).map((c) => ({ value: c.id, label: c.name }))} allLabel="All companies" />
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
            <EmptyState illustration={<EmptySearchIllustration />} title="No applications match these filters" description="Try another status, type or company, or clear the filters." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState illustration={<EmptyBriefcaseIllustration />} title="No applications yet" description="When students apply to a published job or internship, their applications will show up here for review." />
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
                <PersonCell student={row.student} />
                <ApplicationBadge status={row.status} />
              </div>
              <InternshipCell internship={row.opportunity} />
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <ApplicationDrawer id={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </div>
  )
}
