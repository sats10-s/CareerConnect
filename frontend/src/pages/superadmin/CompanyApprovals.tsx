import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { AlertTriangle } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { Tabs } from '@/components/ui/Tabs'
import { Button } from '@/components/ui/Button'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { MetaBadge } from '@/components/common/Cells'
import { CompanyReviewDrawer } from '@/components/superadmin/CompanyReviewDrawer'
import { useApiList } from '@/hooks/useApiList'
import { superAdminService } from '@/services/superAdminService'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import { COMPANY_STATUS } from '@/utils/statusMeta'
import type { CompanyStatus } from '@/types/auth'
import type { CompanyApprovalRecord, CompanyStatusCounts } from '@/types/superAdmin'

const TAB_ORDER: (CompanyStatus | 'ALL')[] = ['PENDING', 'APPROVED', 'REJECTED', 'SUSPENDED', 'ALL']

function CompanyCell({ row }: { row: CompanyApprovalRecord }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <CompanyLogo name={row.name} logoPath={row.logo_path} size="sm" />
      <div className="min-w-0">
        <p className="flex items-center gap-1.5 truncate text-sm font-medium text-ink">
          {row.name}
          {row.possible_duplicates.length > 0 && (
            <span className="inline-flex items-center gap-0.5 text-xs font-medium text-warning" title="Possible duplicate">
              <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />
              <span className="sr-only">Possible duplicate</span>
            </span>
          )}
        </p>
        <p className="truncate text-xs text-ink-faint">{row.website ? row.website.replace(/^https?:\/\//, '') : row.industry ?? '—'}</p>
      </div>
    </div>
  )
}

const contact = (row: CompanyApprovalRecord) => (
  <div className="min-w-0">
    <p className="truncate text-sm text-ink">{row.contact_name ?? '—'}</p>
    <p className="truncate text-xs text-ink-faint">{row.owner?.email ?? row.contact_email ?? ''}</p>
  </div>
)

/**
 * Super Admin > Company Approvals - GET /super-admin/companies. Pending
 * self-registrations first; open a row to approve, reject, suspend or
 * reactivate. Placement Admins see status but cannot change it.
 */
export function SuperAdminCompanyApprovalsPage() {
  const [params, setParams] = useSearchParams()
  const tab = (params.get('status') ?? 'PENDING') as CompanyStatus | 'ALL'
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [counts, setCounts] = useState<CompanyStatusCounts | null>(null)

  const fetcher = useCallback(
    (p: { status?: string; search?: string; page: number; per_page: number }) =>
      superAdminService.companies.list(p).then((data) => {
        setCounts(data.counts)
        return data
      }),
    [],
  )
  const list = useApiList(fetcher, { status: tab === 'ALL' ? undefined : tab, search: search.trim() || undefined })

  const total = counts ? Object.values(counts).reduce((a, b) => a + b, 0) : null
  const tabs = TAB_ORDER.map((key) => {
    const count = key === 'ALL' ? total : counts?.[key]
    const name = key === 'ALL' ? 'All' : COMPANY_STATUS[key].label.replace(' review', '')
    return { key, label: count === null || count === undefined ? name : `${name} (${count})` }
  })
  const setTab = (key: string) => {
    const next = new URLSearchParams(params)
    if (key === 'PENDING') next.delete('status')
    else next.set('status', key)
    setParams(next, { replace: true })
  }

  const columns = [
    { key: 'company', header: 'Company', render: (row: CompanyApprovalRecord) => <CompanyCell row={row} /> },
    { key: 'contact', header: 'Contact', render: contact },
    { key: 'source', header: 'Source', render: (row: CompanyApprovalRecord) => <span className="text-sm text-ink-soft">{row.source === 'SELF_REGISTERED' ? 'Self-registered' : 'Placement office'}</span> },
    { key: 'registered', header: 'Registered', render: (row: CompanyApprovalRecord) => <span className="text-sm text-ink-soft">{row.created_at ? formatRelativeTime(row.created_at) : '—'}</span> },
    { key: 'status', header: 'Status', render: (row: CompanyApprovalRecord) => <MetaBadge map={COMPANY_STATUS} value={row.status} /> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Company Approvals" description="Review self-registered recruiters before they can use the company portal. Every decision is audited and the company is notified." />

      <div className="mb-4 overflow-x-auto">
        <Tabs items={tabs} active={tab} onChange={setTab} />
      </div>

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by name, email or website" className="w-full sm:w-80" />
        {search.trim() && <Button variant="secondary" onClick={() => setSearch('')}>Clear search</Button>}
      </FilterBar>

      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={list.items.length === 0}
        hasLoadedOnce={list.meta !== null}
        onRetry={list.reload}
        empty={
          search.trim() ? (
            <EmptyState illustration={<EmptySearchIllustration />} title="No companies match" description="Try a different name, email or website." />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title={tab === 'PENDING' ? 'No registrations waiting' : 'Nothing here'}
              description={tab === 'PENDING' ? 'New company registrations will appear here for review.' : 'No companies have this status.'}
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
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <CompanyCell row={row} />
                <MetaBadge map={COMPANY_STATUS} value={row.status} />
              </div>
              <p className="text-xs text-ink-faint">{row.contact_name ?? 'No contact'} · {row.created_at ? formatRelativeTime(row.created_at) : ''}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <CompanyReviewDrawer id={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </div>
  )
}
