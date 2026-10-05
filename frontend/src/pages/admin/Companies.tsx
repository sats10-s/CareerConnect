import { useState } from 'react'
import { Plus } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { MetaBadge } from '@/components/common/Cells'
import { ListState } from '@/components/common/ListState'
import { CompanyDrawer } from '@/components/admin/CompanyDrawer'
import { CompanyFormModal } from '@/components/admin/CompanyFormModal'
import { useApiList } from '@/hooks/useApiList'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { COMPANY_STATUS, optionsFor } from '@/utils/statusMeta'
import type { CompanyRecord } from '@/types/admin'

function CompanyCell({ row }: { row: CompanyRecord }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <CompanyLogo name={row.name} logoPath={row.logo_path} size="sm" />
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{row.name}</p>
        <p className="truncate text-xs text-ink-faint">{row.industry ?? 'Industry not set'}</p>
      </div>
    </div>
  )
}

/**
 * Placement Admin > Companies - GET/POST/PATCH /admin/companies. Add and
 * edit companies here, then post jobs and internships for them. No delete:
 * a company's postings, applications and offers depend on it. Status is
 * read-only here: only Super Admins approve or suspend self-registered
 * companies, and students never see postings of a non-approved company.
 */
export function AdminCompaniesPage() {
  const [search, setSearch] = useState('')
  const [industry, setIndustry] = useState('')
  const [status, setStatus] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const industries = useApiResource(() => adminService.companies.list({ per_page: 1 }).then((d) => d.industries), 'company-industries')

  const list = useApiList(adminService.companies.list, { search: search.trim() || undefined, industry: industry || undefined, status: status || undefined })
  const hasFilters = Boolean(search.trim() || industry || status)
  const clearFilters = () => {
    setSearch('')
    setIndustry('')
    setStatus('')
  }
  const refresh = () => {
    list.reload()
    industries.reload()
  }

  const columns = [
    { key: 'company', header: 'Company', render: (row: CompanyRecord) => <CompanyCell row={row} /> },
    { key: 'website', header: 'Website', render: (row: CompanyRecord) => <span className="text-sm text-ink-soft">{row.website ? row.website.replace(/^https?:\/\//, '') : '—'}</span> },
    { key: 'jobs', header: 'Jobs', render: (row: CompanyRecord) => <span className="tabular-nums">{row.job_postings_count ?? 0}</span> },
    { key: 'internships', header: 'Internships', render: (row: CompanyRecord) => <span className="tabular-nums">{row.internships_count ?? 0}</span> },
    { key: 'status', header: 'Status', render: (row: CompanyRecord) => <MetaBadge map={COMPANY_STATUS} value={row.status} /> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Companies" description="The recruiters your jobs, internships and drives belong to." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by name or industry" className="w-full sm:w-80" />
        <FilterSelect label="Industry" value={industry} onChange={setIndustry} options={(industries.data ?? []).map((i) => ({ value: i, label: i }))} allLabel="All industries" />
        <FilterSelect label="Status" value={status} onChange={setStatus} options={optionsFor(COMPANY_STATUS)} allLabel="All statuses" />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
        <div className="w-full sm:ml-auto sm:w-auto">
          <Button className="w-full sm:w-auto" onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" />
            Add company
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
            <EmptyState illustration={<EmptySearchIllustration />} title="No companies match" description="Try a different name or industry, or clear the filters." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState illustration={<EmptyBriefcaseIllustration />} title="No companies yet" description="Add the first recruiter, then post jobs and internships for them." action={<Button onClick={() => setCreating(true)}>Add company</Button>} />
          )
        }
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={(row) => setOpenId(row.id)}
          renderMobileCard={(row) => (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <CompanyCell row={row} />
                <MetaBadge map={COMPANY_STATUS} value={row.status} />
              </div>
              <p className="text-xs text-ink-faint">{row.job_postings_count ?? 0} job{row.job_postings_count === 1 ? '' : 's'} · {row.internships_count ?? 0} internship{row.internships_count === 1 ? '' : 's'}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <CompanyDrawer id={openId} onClose={() => setOpenId(null)} onChanged={refresh} />
      {creating && <CompanyFormModal onClose={() => setCreating(false)} onSaved={(c) => { refresh(); setOpenId(c.id) }} />}
    </div>
  )
}
