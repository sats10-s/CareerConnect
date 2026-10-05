import { useCallback, useState } from 'react'
import { Plus } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { SearchBar } from '@/components/ui/SearchBar'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { MetaBadge } from '@/components/common/Cells'
import { ApprovedGate } from '@/components/company/ApprovedGate'
import { PostingDrawer, type PostingRef } from '@/components/company/PostingDrawer'
import { PostingFormModal } from '@/components/company/PostingFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { companyService } from '@/services/companyService'
import { formatDate } from '@/utils/dateFormat'
import { OPPORTUNITY_STATUS, POSTING_REVIEW_STATE, optionsFor } from '@/utils/statusMeta'
import type { CompanyPosting } from '@/types/company'

const TYPE_OPTIONS = [
  { value: 'job', label: 'Jobs' },
  { value: 'internship', label: 'Internships' },
]

function PostingsList() {
  const [type, setType] = useState('')
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState<PostingRef | null>(null)
  const [creating, setCreating] = useState(false)

  const params = { type: type || undefined, status: status || undefined, search: search.trim() || undefined }
  const key = JSON.stringify(params)
  const fetcher = useCallback(() => companyService.postings.list(JSON.parse(key)), [key])
  const list = useApiResource(fetcher, key)
  const items = list.data ?? []
  const hasFilters = Boolean(type || status || search.trim())
  const clear = () => {
    setType('')
    setStatus('')
    setSearch('')
  }

  const columns = [
    {
      key: 'title',
      header: 'Posting',
      render: (row: CompanyPosting) => (
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-ink">{row.title}</p>
          <p className="truncate text-xs text-ink-faint">{row.type === 'job' ? 'Job' : 'Internship'}{row.location ? ` · ${row.location}` : ''}</p>
        </div>
      ),
    },
    { key: 'state', header: 'Status', render: (row: CompanyPosting) => <MetaBadge map={POSTING_REVIEW_STATE} value={row.review_state} /> },
    { key: 'applicants', header: 'Applicants', render: (row: CompanyPosting) => <span className="tabular-nums">{row.applications_count}</span> },
    { key: 'deadline', header: 'Deadline', render: (row: CompanyPosting) => formatDate(row.application_deadline) },
    { key: 'created', header: 'Created', render: (row: CompanyPosting) => <span className="text-sm text-ink-soft">{formatDate(row.created_at)}</span> },
  ]

  return (
    <>
      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by title" className="w-full sm:w-72" />
        <FilterSelect label="Type" value={type} onChange={setType} options={TYPE_OPTIONS} allLabel="Jobs & internships" />
        <FilterSelect label="Status" value={status} onChange={setStatus} options={optionsFor(OPPORTUNITY_STATUS)} allLabel="All statuses" />
        {hasFilters && <Button variant="secondary" onClick={clear}>Clear filters</Button>}
        <div className="w-full sm:ml-auto sm:w-auto">
          <Button className="w-full sm:w-auto" onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" />
            New posting
          </Button>
        </div>
      </FilterBar>

      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={items.length === 0}
        hasLoadedOnce={list.data !== null}
        onRetry={list.reload}
        empty={hasFilters ? (
          <EmptyState illustration={<EmptySearchIllustration />} title="No postings match" description="Try another type or status." action={<Button variant="secondary" onClick={clear}>Clear filters</Button>} />
        ) : (
          <EmptyState illustration={<EmptyBriefcaseIllustration />} title="No postings yet" description="Create a job or internship. It starts as a draft only your team can see." action={<Button onClick={() => setCreating(true)}><Plus className="h-4 w-4" />New posting</Button>} />
        )}
      >
        <DataTable
          columns={columns}
          rows={items}
          rowKey={(row) => `${row.type}-${row.id}`}
          onRowClick={(row) => setOpen({ type: row.type, id: row.id })}
          renderMobileCard={(row) => (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <p className="min-w-0 truncate text-sm font-medium text-ink">{row.title}</p>
                <MetaBadge map={POSTING_REVIEW_STATE} value={row.review_state} />
              </div>
              <p className="text-xs text-ink-faint">{row.type === 'job' ? 'Job' : 'Internship'} · {row.applications_count} applicant{row.applications_count === 1 ? '' : 's'}</p>
            </div>
          )}
        />
      </ListState>

      <PostingDrawer posting={open} onClose={() => setOpen(null)} onChanged={list.reload} />
      {creating && <PostingFormModal onClose={() => setCreating(false)} onSaved={(p) => { list.reload(); setOpen({ type: p.type, id: p.id }) }} />}
    </>
  )
}

/** /company/postings - the company's jobs and internships. */
export function CompanyPostingsPage() {
  return (
    <div className="animate-fade-in">
      <PageHeader title="Postings" description="Draft jobs and internships, then submit them. The placement office reviews eligibility and publishes them to students." />
      <ApprovedGate>
        <PostingsList />
      </ApprovedGate>
    </div>
  )
}
