import { useState } from 'react'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { MetaBadge } from '@/components/common/Cells'
import { OpportunityDrawer } from './OpportunityDrawer'
import { OpportunityFormModal } from './OpportunityFormModal'
import { useCompanyOptions } from '@/hooks/useAdminOptions'
import { useApiList } from '@/hooks/useApiList'
import { adminService } from '@/services/adminService'
import { formatDate } from '@/utils/dateFormat'
import { OPPORTUNITY_STATUS, optionsFor } from '@/utils/statusMeta'
import type { AdminOpportunity, OpportunityLifecycle } from '@/types/admin'

/** List + create + detail drawer for admin job postings or internships (they share every behaviour; only the endpoint and a few fields differ). */
export function OpportunityList({ kind }: { kind: 'job' | 'internship' }) {
  const api = kind === 'job' ? adminService.jobs : adminService.internships
  const noun = kind === 'job' ? 'job' : 'internship'
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const companies = useCompanyOptions()

  const list = useApiList(api.list, {
    search: search.trim() || undefined,
    status: (status || undefined) as OpportunityLifecycle | undefined,
    company_id: companyId ? Number(companyId) : undefined,
  })

  const hasFilters = Boolean(search.trim() || status || companyId)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
    setCompanyId('')
  }

  const columns = [
    {
      key: 'title',
      header: kind === 'job' ? 'Job' : 'Internship',
      render: (row: AdminOpportunity) => (
        <div className="flex min-w-0 items-center gap-3">
          <CompanyLogo name={row.company?.name} logoPath={row.company?.logo_path} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{row.title}</p>
            <p className="truncate text-xs text-ink-faint">{row.company?.name ?? '—'}{row.location ? ` · ${row.location}` : ''}</p>
          </div>
        </div>
      ),
    },
    { key: 'status', header: 'Status', render: (row: AdminOpportunity) => <MetaBadge map={OPPORTUNITY_STATUS} value={row.status} /> },
    { key: 'deadline', header: 'Deadline', render: (row: AdminOpportunity) => formatDate(row.application_deadline) },
    { key: 'openings', header: 'Openings', render: (row: AdminOpportunity) => row.openings ?? '—' },
    { key: 'updated', header: 'Updated', render: (row: AdminOpportunity) => <span className="text-sm text-ink-soft">{formatDate(row.updated_at)}</span> },
  ]

  return (
    <div>
      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder={`Search ${noun}s by title or company`} className="w-full sm:w-72" />
        <FilterSelect label="Status" value={status} onChange={setStatus} options={optionsFor(OPPORTUNITY_STATUS)} allLabel="All statuses" />
        <FilterSelect label="Company" value={companyId} onChange={setCompanyId} options={(companies.data ?? []).map((c) => ({ value: c.id, label: c.name }))} allLabel="All companies" />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
        <div className="w-full sm:ml-auto sm:w-auto">
          <Button onClick={() => setCreating(true)} className="w-full sm:w-auto">
            <Plus className="h-4 w-4" />
            New {noun}
          </Button>
        </div>
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
              title={`No ${noun}s match these filters`}
              description="Try another status or company, or clear the filters."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title={`No ${noun}s posted yet`}
              description={`Create the first ${noun} posting. It starts as a draft, and students only see it once you publish it.`}
              action={<Button onClick={() => setCreating(true)}><Plus className="h-4 w-4" />New {noun}</Button>}
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
                <MetaBadge map={OPPORTUNITY_STATUS} value={row.status} />
              </div>
              <p className="text-xs text-ink-faint">Deadline {formatDate(row.application_deadline)} · {row.openings ?? '—'} openings</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      {creating && <OpportunityFormModal kind={kind} onClose={() => setCreating(false)} onSaved={(saved) => { list.reload(); setOpenId(saved.id) }} />}
      <OpportunityDrawer kind={kind} id={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </div>
  )
}
