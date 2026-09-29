import { useState } from 'react'
import { Plus } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { EmptyCalendarIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { MetaBadge } from '@/components/common/Cells'
import { DriveDrawer } from '@/components/admin/DriveDrawer'
import { DriveFormModal } from '@/components/admin/DriveFormModal'
import { useCompanyOptions } from '@/hooks/useAdminOptions'
import { useApiList } from '@/hooks/useApiList'
import { adminService, type AdminDriveParams } from '@/services/adminService'
import { formatDateTime } from '@/utils/dateFormat'
import { DRIVE_STATUS, DRIVE_TYPE_LABEL, optionsFor } from '@/utils/statusMeta'
import type { DriveRow, DriveStatus, DriveType } from '@/types/admin'

/** Placement Admin > Placement Drives - /api/admin/placement-drives: college recruitment events that group a company's jobs/internships. */
export function AdminPlacementDrivesPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [when, setWhen] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const companies = useCompanyOptions()

  const list = useApiList(adminService.drives.list, {
    search: search.trim() || undefined,
    status: (status || undefined) as DriveStatus | undefined,
    drive_type: (type || undefined) as DriveType | undefined,
    company_id: companyId ? Number(companyId) : undefined,
    when: (when || undefined) as AdminDriveParams['when'],
  })

  const hasFilters = Boolean(search.trim() || status || type || companyId || when)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
    setType('')
    setCompanyId('')
    setWhen('')
  }

  const columns = [
    {
      key: 'title',
      header: 'Drive',
      render: (row: DriveRow) => (
        <div className="flex min-w-0 items-center gap-3">
          <CompanyLogo name={row.company?.name} logoPath={row.company?.logo_path} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{row.title}</p>
            <p className="truncate text-xs text-ink-faint">{row.company?.name ?? '—'} · {DRIVE_TYPE_LABEL[row.drive_type] ?? row.drive_type}</p>
          </div>
        </div>
      ),
    },
    { key: 'status', header: 'Status', render: (row: DriveRow) => <MetaBadge map={DRIVE_STATUS} value={row.status} /> },
    { key: 'when', header: 'Drive date', render: (row: DriveRow) => <span className="whitespace-nowrap">{formatDateTime(row.scheduled_at)}</span> },
    { key: 'openings', header: 'Openings', render: (row: DriveRow) => row.opportunity_count },
    { key: 'apps', header: 'Applications', render: (row: DriveRow) => row.application_count },
    { key: 'selected', header: 'Selected', render: (row: DriveRow) => row.selected_count },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Placement Drives"
        description="Recruitment events that group a company's jobs and internships. A drive needs at least one opening before it can be published."
        action={<Button onClick={() => setCreating(true)}><Plus className="h-4 w-4" />New drive</Button>}
      />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by title or company" className="w-full sm:w-72" />
        <FilterSelect label="Drive status" value={status} onChange={setStatus} options={optionsFor(DRIVE_STATUS)} allLabel="All statuses" />
        <FilterSelect label="Drive type" value={type} onChange={setType} options={Object.entries(DRIVE_TYPE_LABEL).map(([value, label]) => ({ value, label }))} allLabel="All types" />
        <FilterSelect label="Company" value={companyId} onChange={setCompanyId} options={(companies.data ?? []).map((c) => ({ value: c.id, label: c.name }))} allLabel="All companies" />
        <FilterSelect label="Timing" value={when} onChange={setWhen} options={[{ value: 'upcoming', label: 'Upcoming' }, { value: 'past', label: 'Past' }]} allLabel="Any time" />
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
            <EmptyState illustration={<EmptySearchIllustration />} title="No drives match these filters" description="Try another status, type or timing, or clear the filters." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState
              illustration={<EmptyCalendarIllustration />}
              title="No placement drives yet"
              description="Create a drive for a company visiting campus, attach its openings, then publish it for students."
              action={<Button onClick={() => setCreating(true)}><Plus className="h-4 w-4" />New drive</Button>}
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
                <div className="flex min-w-0 items-center gap-3">
                  <CompanyLogo name={row.company?.name} logoPath={row.company?.logo_path} size="sm" />
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">{row.title}</p>
                    <p className="truncate text-xs text-ink-faint">{row.company?.name}</p>
                  </div>
                </div>
                <MetaBadge map={DRIVE_STATUS} value={row.status} />
              </div>
              <p className="text-xs text-ink-faint">{formatDateTime(row.scheduled_at)} · {row.opportunity_count} openings · {row.application_count} applications</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      {creating && <DriveFormModal onClose={() => setCreating(false)} onSaved={(drive) => { list.reload(); setOpenId(drive.id) }} />}
      <DriveDrawer id={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </div>
  )
}
