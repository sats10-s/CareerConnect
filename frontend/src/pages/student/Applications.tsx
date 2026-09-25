import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle, Briefcase, SearchX } from 'lucide-react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { SearchBar } from '@/components/ui/SearchBar'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Textarea } from '@/components/ui/Textarea'
import { StatusBadge, toneForApplicationStatus, labelForApplicationStatus } from '@/components/ui/StatusBadge'
import { applicationService } from '@/services/applicationService'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { ApplicationDetail, ApplicationListItem, ApplicationStatus } from '@/types/application'
import type { PaginationMeta } from '@/types/common'

type TypeFilter = 'all' | 'job' | 'internship'

const STATUS_OPTIONS: { value: ApplicationStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All statuses' },
  { value: 'APPLIED', label: 'Applied' },
  { value: 'UNDER_REVIEW', label: 'Under Review' },
  { value: 'SHORTLISTED', label: 'Shortlisted' },
  { value: 'ASSESSMENT', label: 'Assessment' },
  { value: 'INTERVIEW', label: 'Interview' },
  { value: 'SELECTED', label: 'Selected' },
  { value: 'REJECTED', label: 'Rejected' },
  { value: 'WITHDRAWN', label: 'Withdrawn' },
]

const TERMINAL_STATUSES: ApplicationStatus[] = ['SELECTED', 'REJECTED', 'WITHDRAWN']

export function StudentApplicationsPage() {
  const [status, setStatus] = useState<ApplicationStatus | 'all'>('all')
  const [type, setType] = useState<TypeFilter>('all')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const [items, setItems] = useState<ApplicationListItem[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<ApplicationDetail | null>(null)
  const [isLoadingDetail, setIsLoadingDetail] = useState(false)

  const [confirmingWithdraw, setConfirmingWithdraw] = useState(false)
  const [withdrawRemarks, setWithdrawRemarks] = useState('')
  const [isWithdrawing, setIsWithdrawing] = useState(false)

  const hasActiveFilters = status !== 'all' || type !== 'all' || search.trim() !== ''

  const load = useCallback(() => {
    setIsLoading(true)
    setLoadError(false)
    applicationService
      .list({
        status: status === 'all' ? undefined : status,
        type: type === 'all' ? undefined : type,
        search: search.trim() || undefined,
        page,
        per_page: 15,
      })
      .then((data) => {
        setItems(data.items)
        setMeta(data.meta)
      })
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }, [status, type, search, page])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    if (selectedId === null) {
      setDetail(null)
      return
    }
    setIsLoadingDetail(true)
    applicationService
      .show(selectedId)
      .then(setDetail)
      .finally(() => setIsLoadingDetail(false))
  }, [selectedId])

  function resetFilters() {
    setStatus('all')
    setType('all')
    setSearch('')
    setPage(1)
  }

  function handleWithdraw() {
    if (selectedId === null) return
    setIsWithdrawing(true)
    applicationService
      .withdraw(selectedId, withdrawRemarks.trim() || undefined)
      .then((updated) => {
        setDetail(updated)
        setConfirmingWithdraw(false)
        setWithdrawRemarks('')
        load()
      })
      .finally(() => setIsWithdrawing(false))
  }

  const isDetailWithdrawable = detail ? !TERMINAL_STATUSES.includes(detail.status) : false

  return (
    <div className="space-y-6">
      <SectionHeader title="Applications" subtitle="Every job and internship you've applied to, and where it stands." />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <SearchBar
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search by title..."
          className="sm:max-w-xs"
        />
        <Select
          value={status}
          onChange={(event) => {
            setStatus(event.target.value as ApplicationStatus | 'all')
            setPage(1)
          }}
          className="sm:max-w-[10rem]"
          aria-label="Filter by status"
        >
          {STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </div>

      <Tabs
        items={[
          { key: 'all', label: 'All' },
          { key: 'job', label: 'Jobs' },
          { key: 'internship', label: 'Internships' },
        ]}
        active={type}
        onChange={(key) => {
          setType(key as TypeFilter)
          setPage(1)
        }}
      />

      {isLoading ? (
        <div className="card p-5">
          <LoadingSkeletonLines count={5} className="h-16 w-full" />
        </div>
      ) : loadError ? (
        <div className="card">
          <EmptyState
            icon={AlertTriangle}
            title="Couldn't load your applications"
            description="Something went wrong reaching the server. Check your connection and try again."
            action={
              <Button variant="secondary" onClick={load}>
                Retry
              </Button>
            }
          />
        </div>
      ) : items.length === 0 ? (
        <div className="card">
          {hasActiveFilters ? (
            <EmptyState
              icon={SearchX}
              title="No applications match your filters"
              description="Try a different status, type, or search term."
              action={
                <Button variant="secondary" onClick={resetFilters}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={Briefcase}
              title="You haven't applied to anything yet"
              description="Browse open jobs and internships to get started."
              action={
                <Link to="/student/jobs">
                  <Button>Browse jobs</Button>
                </Link>
              }
            />
          )}
        </div>
      ) : (
        <>
          <DataTable
            columns={[
              {
                key: 'opportunity',
                header: 'Opportunity',
                render: (row) => (
                  <div className="min-w-0">
                    <p className="truncate font-medium text-ink">{row.opportunity?.title ?? 'Opportunity unavailable'}</p>
                    <p className="truncate text-xs text-ink-soft">
                      {row.opportunity?.company?.name ?? '—'} · {row.opportunity?.type === 'internship' ? 'Internship' : 'Job'}
                    </p>
                  </div>
                ),
              },
              {
                key: 'status',
                header: 'Status',
                render: (row) => (
                  <StatusBadge tone={toneForApplicationStatus(row.status)}>{labelForApplicationStatus(row.status)}</StatusBadge>
                ),
              },
              {
                key: 'applied_at',
                header: 'Applied',
                render: (row) => <span className="text-ink-soft">{row.applied_at ? formatRelativeTime(row.applied_at) : '—'}</span>,
              },
            ]}
            rows={items}
            rowKey={(row) => row.id}
            onRowClick={(row) => setSelectedId(row.id)}
            renderMobileCard={(row) => (
              <div className="space-y-2">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium text-ink">{row.opportunity?.title ?? 'Opportunity unavailable'}</p>
                    <p className="truncate text-xs text-ink-soft">{row.opportunity?.company?.name ?? '—'}</p>
                  </div>
                  <StatusBadge tone={toneForApplicationStatus(row.status)}>{labelForApplicationStatus(row.status)}</StatusBadge>
                </div>
                <p className="text-xs text-ink-faint">Applied {row.applied_at ? formatRelativeTime(row.applied_at) : '—'}</p>
              </div>
            )}
          />

          {meta && meta.last_page > 1 && <Pagination page={meta.current_page} totalPages={meta.last_page} onChange={setPage} />}
        </>
      )}

      <Modal
        open={selectedId !== null}
        onClose={() => setSelectedId(null)}
        title={detail?.opportunity?.title ?? 'Application'}
        footer={
          isDetailWithdrawable ? (
            <Button variant="danger" onClick={() => setConfirmingWithdraw(true)}>
              Withdraw application
            </Button>
          ) : undefined
        }
      >
        {isLoadingDetail || !detail ? (
          <LoadingSkeletonLines count={4} className="h-4 w-full" />
        ) : (
          <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-3">
              <StatusBadge tone={toneForApplicationStatus(detail.status)}>{labelForApplicationStatus(detail.status)}</StatusBadge>
              <span className="text-xs text-ink-soft">
                {detail.opportunity?.company?.name} · {detail.opportunity?.type === 'internship' ? 'Internship' : 'Job'}
              </span>
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <dt className="text-ink-soft">Applied</dt>
              <dd className="text-ink">{detail.applied_at ? formatRelativeTime(detail.applied_at) : '—'}</dd>
              {detail.withdrawn_at && (
                <>
                  <dt className="text-ink-soft">Withdrawn</dt>
                  <dd className="text-ink">{formatRelativeTime(detail.withdrawn_at)}</dd>
                </>
              )}
              {detail.selected_at && (
                <>
                  <dt className="text-ink-soft">Selected</dt>
                  <dd className="text-ink">{formatRelativeTime(detail.selected_at)}</dd>
                </>
              )}
            </dl>

            {detail.rejection_reason && (
              <div className="rounded-lg bg-error-bg p-3 text-sm text-error">{detail.rejection_reason}</div>
            )}
            {detail.notes && <p className="text-sm text-ink-soft">{detail.notes}</p>}

            {detail.status_history.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">Status history</p>
                <ol className="space-y-3">
                  {detail.status_history.map((entry, index) => (
                    <li key={entry.id} className="relative flex gap-3 pl-1">
                      {index !== detail.status_history.length - 1 && (
                        <span className="absolute left-[5px] top-4 h-full w-px bg-hairline" aria-hidden="true" />
                      )}
                      <span className="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                      <div className="pb-3 text-sm">
                        <p className="text-ink">
                          {entry.from_status ? `${labelForApplicationStatus(entry.from_status)} → ` : ''}
                          <span className="font-medium">{labelForApplicationStatus(entry.to_status)}</span>
                        </p>
                        {entry.remarks && <p className="mt-0.5 text-ink-soft">{entry.remarks}</p>}
                        <p className="mt-0.5 text-xs text-ink-faint">
                          {formatRelativeTime(entry.created_at)}
                          {entry.changed_by ? ` · ${entry.changed_by.name}` : ''}
                        </p>
                      </div>
                    </li>
                  ))}
                </ol>
              </div>
            )}
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={confirmingWithdraw}
        onClose={() => setConfirmingWithdraw(false)}
        onConfirm={handleWithdraw}
        title="Withdraw this application?"
        description="This can't be undone. You can optionally tell us why."
        confirmLabel="Withdraw"
        tone="danger"
        isConfirming={isWithdrawing}
      >
        <Textarea
          value={withdrawRemarks}
          onChange={(event) => setWithdrawRemarks(event.target.value)}
          placeholder="Reason (optional)"
          rows={3}
          className="mt-3"
        />
      </ConfirmDialog>
    </div>
  )
}
