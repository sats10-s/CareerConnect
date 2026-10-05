import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyCalendarIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { InterviewDrawer } from '@/components/admin/InterviewDrawer'
import { ApprovedGate } from '@/components/company/ApprovedGate'
import { COMPANY_INTERVIEWS } from '@/components/company/interviewServices'
import { useApiList } from '@/hooks/useApiList'
import { companyService } from '@/services/companyService'
import { formatDateTime } from '@/utils/dateFormat'
import { INTERVIEW_MODE_LABEL, INTERVIEW_STATUS, optionsFor } from '@/utils/statusMeta'
import type { CompanyInterview } from '@/types/company'

function InterviewsList() {
  const [status, setStatus] = useState('')
  const [when, setWhen] = useState('upcoming')
  const [openId, setOpenId] = useState<number | null>(null)
  const list = useApiList(companyService.interviews.list, { status: status || undefined, upcoming: when === 'upcoming' ? (1 as const) : undefined })
  const hasFilters = Boolean(status) || when !== 'upcoming'

  const columns = [
    { key: 'when', header: 'When', render: (row: CompanyInterview) => <span className="text-sm text-ink">{formatDateTime(row.scheduled_at)}</span> },
    { key: 'student', header: 'Applicant', render: (row: CompanyInterview) => <PersonCell student={row.student} subtitle={row.student?.roll_number} /> },
    { key: 'posting', header: 'Posting', render: (row: CompanyInterview) => <InternshipCell internship={row.opportunity} /> },
    { key: 'round', header: 'Round', render: (row: CompanyInterview) => <span className="text-sm">{row.round} · {INTERVIEW_MODE_LABEL[row.mode] ?? row.mode}</span> },
    { key: 'status', header: 'Status', render: (row: CompanyInterview) => <MetaBadge map={INTERVIEW_STATUS} value={row.status} /> },
  ]

  return (
    <>
      <FilterBar>
        <FilterSelect label="Show" value={when} onChange={setWhen} options={[{ value: 'upcoming', label: 'Upcoming' }]} allLabel="All dates" />
        <FilterSelect label="Status" value={status} onChange={setStatus} options={optionsFor(INTERVIEW_STATUS)} allLabel="All statuses" />
        {hasFilters && <Button variant="secondary" onClick={() => { setStatus(''); setWhen('upcoming') }}>Reset</Button>}
      </FilterBar>

      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={list.items.length === 0}
        hasLoadedOnce={list.meta !== null}
        onRetry={list.reload}
        empty={hasFilters ? (
          <EmptyState illustration={<EmptySearchIllustration />} title="No interviews match" description="Try another status or show all dates." />
        ) : (
          <EmptyState illustration={<EmptyCalendarIllustration />} title="No upcoming interviews" description="Shortlist an applicant, then schedule an interview from their application." />
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
                <PersonCell student={row.student} subtitle={formatDateTime(row.scheduled_at)} />
                <MetaBadge map={INTERVIEW_STATUS} value={row.status} />
              </div>
              <p className="text-xs text-ink-faint">{row.opportunity?.title} · Round {row.round}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <InterviewDrawer id={openId} service={COMPANY_INTERVIEWS} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </>
  )
}

/** /company/interviews - interviews with the company's applicants. */
export function CompanyInterviewsPage() {
  return (
    <div className="animate-fade-in">
      <PageHeader title="Interviews" description="Reschedule, complete or cancel interviews. Students are notified of every change." />
      <ApprovedGate>
        <InterviewsList />
      </ApprovedGate>
    </div>
  )
}
