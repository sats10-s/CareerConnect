import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { EmptySearchIllustration, EmptyBriefcaseIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { ApplicationBadge } from '@/components/common/Cells'
import { labelForApplicationStatus } from '@/components/ui/StatusBadge'
import { ApprovedGate } from '@/components/company/ApprovedGate'
import { ApplicantDrawer } from '@/components/company/ApplicantDrawer'
import { useApiList } from '@/hooks/useApiList'
import { useApiResource } from '@/hooks/useApiResource'
import { companyService } from '@/services/companyService'
import { formatDate } from '@/utils/dateFormat'
import type { ApplicationStatus } from '@/types/application'
import type { CompanyApplicant } from '@/types/company'

const STATUSES: ApplicationStatus[] = ['APPLIED', 'UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'INTERVIEW', 'SELECTED', 'REJECTED', 'WITHDRAWN']

function ApplicantCell({ row }: { row: CompanyApplicant }) {
  return (
    <div className="min-w-0">
      <p className="truncate text-sm font-medium text-ink">{row.student.name ?? 'Student'}</p>
      <p className="truncate text-xs text-ink-faint">{[row.student.roll_number, row.student.department].filter(Boolean).join(' · ')}</p>
    </div>
  )
}

function ApplicantsList() {
  const [params, setParams] = useSearchParams()
  const posting = params.get('type') && params.get('posting') ? `${params.get('type')}:${params.get('posting')}` : ''
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const postings = useApiResource(() => companyService.postings.list(), 'company-posting-options')

  const [type, postingId] = posting ? posting.split(':') : []
  const list = useApiList(companyService.applicants.list, {
    type: (type || undefined) as 'job' | 'internship' | undefined,
    posting_id: postingId ? Number(postingId) : undefined,
    status: (status || undefined) as ApplicationStatus | undefined,
    search: search.trim() || undefined,
  })
  const setPosting = (value: string) => {
    const next = new URLSearchParams(params)
    if (value) {
      const [t, id] = value.split(':')
      next.set('type', t)
      next.set('posting', id)
    } else {
      next.delete('type')
      next.delete('posting')
    }
    setParams(next, { replace: true })
  }
  const hasFilters = Boolean(posting || status || search.trim())
  const clear = () => {
    setPosting('')
    setStatus('')
    setSearch('')
  }

  const columns = [
    { key: 'student', header: 'Applicant', render: (row: CompanyApplicant) => <ApplicantCell row={row} /> },
    { key: 'posting', header: 'Posting', render: (row: CompanyApplicant) => <span className="text-sm text-ink-soft">{row.opportunity?.title ?? '—'}</span> },
    { key: 'cgpa', header: 'CGPA', render: (row: CompanyApplicant) => <span className="tabular-nums">{row.student.cgpa != null ? row.student.cgpa.toFixed(2) : '—'}</span> },
    { key: 'applied', header: 'Applied', render: (row: CompanyApplicant) => <span className="text-sm text-ink-soft">{formatDate(row.applied_at)}</span> },
    { key: 'status', header: 'Status', render: (row: CompanyApplicant) => <ApplicationBadge status={row.status} /> },
  ]

  return (
    <>
      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by name or roll number" className="w-full sm:w-72" />
        <FilterSelect
          label="Posting"
          value={posting}
          onChange={setPosting}
          options={(postings.data ?? []).map((p) => ({ value: `${p.type}:${p.id}`, label: p.title }))}
          allLabel="All postings"
        />
        <FilterSelect label="Status" value={status} onChange={setStatus} options={STATUSES.map((s) => ({ value: s, label: labelForApplicationStatus(s) }))} allLabel="All statuses" />
        {hasFilters && <Button variant="secondary" onClick={clear}>Clear filters</Button>}
      </FilterBar>

      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={list.items.length === 0}
        hasLoadedOnce={list.meta !== null}
        onRetry={list.reload}
        empty={hasFilters ? (
          <EmptyState illustration={<EmptySearchIllustration />} title="No applicants match" description="Try another posting or status." action={<Button variant="secondary" onClick={clear}>Clear filters</Button>} />
        ) : (
          <EmptyState illustration={<EmptyBriefcaseIllustration />} title="No applicants yet" description="Students who apply to your live postings show up here." />
        )}
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={(row) => setOpenId(row.id)}
          renderMobileCard={(row) => (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <ApplicantCell row={row} />
                <ApplicationBadge status={row.status} />
              </div>
              <p className="text-xs text-ink-faint">{row.opportunity?.title} · {formatDate(row.applied_at)}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <ApplicantDrawer id={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </>
  )
}

/** /company/applicants - students who applied to the company's postings. */
export function CompanyApplicantsPage() {
  return (
    <div className="animate-fade-in">
      <PageHeader title="Applicants" description="Review applicants to your postings, move them through your process and schedule interviews." />
      <ApprovedGate>
        <ApplicantsList />
      </ApprovedGate>
    </div>
  )
}
