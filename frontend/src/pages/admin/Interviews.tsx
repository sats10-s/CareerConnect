import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyCalendarIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterDate, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { InterviewDrawer } from '@/components/admin/InterviewDrawer'
import { useCompanyOptions } from '@/hooks/useAdminOptions'
import { useApiList } from '@/hooks/useApiList'
import { adminService } from '@/services/adminService'
import { formatDateTime } from '@/utils/dateFormat'
import { INTERVIEW_MODE_LABEL, INTERVIEW_STATUS, optionsFor } from '@/utils/statusMeta'
import type { InterviewMode, InterviewRow, InterviewStatus } from '@/types/admin'

/**
 * Placement Admin > Interviews - /api/admin/interviews. Interviews are
 * created from an application (Applications > Schedule interview),
 * because each one belongs to a specific application; this page is
 * where they are found, changed, completed or cancelled.
 */
export function AdminInterviewsPage() {
  const [status, setStatus] = useState('')
  const [mode, setMode] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const companies = useCompanyOptions()

  const list = useApiList(adminService.interviews.list, {
    status: (status || undefined) as InterviewStatus | undefined,
    mode: (mode || undefined) as InterviewMode | undefined,
    company_id: companyId ? Number(companyId) : undefined,
    date_from: from || undefined,
    date_to: to || undefined,
  })

  const hasFilters = Boolean(status || mode || companyId || from || to)
  const clearFilters = () => {
    setStatus('')
    setMode('')
    setCompanyId('')
    setFrom('')
    setTo('')
  }

  const columns = [
    { key: 'when', header: 'When', render: (row: InterviewRow) => <span className="whitespace-nowrap font-medium">{formatDateTime(row.scheduled_at)}</span> },
    { key: 'student', header: 'Student', render: (row: InterviewRow) => <PersonCell student={row.student} /> },
    { key: 'opportunity', header: 'For', render: (row: InterviewRow) => <InternshipCell internship={row.opportunity} /> },
    { key: 'round', header: 'Round', render: (row: InterviewRow) => `Round ${row.round}` },
    { key: 'mode', header: 'Mode', render: (row: InterviewRow) => INTERVIEW_MODE_LABEL[row.mode] ?? row.mode },
    { key: 'status', header: 'Status', render: (row: InterviewRow) => <MetaBadge map={INTERVIEW_STATUS} value={row.status} /> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Interviews" description="All scheduled interviews. Schedule new ones from an application." />

      <FilterBar>
        <FilterSelect label="Interview status" value={status} onChange={setStatus} options={optionsFor(INTERVIEW_STATUS)} allLabel="All statuses" />
        <FilterSelect label="Mode" value={mode} onChange={setMode} options={Object.entries(INTERVIEW_MODE_LABEL).map(([value, label]) => ({ value, label }))} allLabel="Any mode" />
        <FilterSelect label="Company" value={companyId} onChange={setCompanyId} options={(companies.data ?? []).map((c) => ({ value: c.id, label: c.name }))} allLabel="All companies" />
        <FilterDate label="From" value={from} onChange={setFrom} />
        <FilterDate label="To" value={to} onChange={setTo} />
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
            <EmptyState illustration={<EmptySearchIllustration />} title="No interviews match these filters" description="Try another status, mode or date range, or clear the filters." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState illustration={<EmptyCalendarIllustration />} title="No interviews scheduled" description="Open a shortlisted application and choose Schedule interview to put one on the calendar." />
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
                <PersonCell student={row.student} subtitle={formatDateTime(row.scheduled_at)} />
                <MetaBadge map={INTERVIEW_STATUS} value={row.status} />
              </div>
              <InternshipCell internship={row.opportunity} />
              <p className="text-xs text-ink-faint">Round {row.round} · {INTERVIEW_MODE_LABEL[row.mode] ?? row.mode}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <InterviewDrawer id={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </div>
  )
}
