import { useCallback, useEffect, useState } from 'react'
import { isAxiosError } from 'axios'
import { AlertTriangle, Briefcase, GraduationCap, SearchX } from 'lucide-react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { SearchBar } from '@/components/ui/SearchBar'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { toast } from '@/components/ui/Toast'
import { opportunityService } from '@/services/opportunityService'
import type { OpportunityDetail, OpportunityListItem, OpportunityType, WorkMode } from '@/types/opportunity'
import type { PaginationMeta } from '@/types/common'

const WORK_MODE_OPTIONS: { value: WorkMode | 'all'; label: string }[] = [
  { value: 'all', label: 'Any work mode' },
  { value: 'on_site', label: 'On-site' },
  { value: 'remote', label: 'Remote' },
  { value: 'hybrid', label: 'Hybrid' },
]

function formatWorkMode(mode: string | null): string {
  switch (mode) {
    case 'on_site':
      return 'On-site'
    case 'remote':
      return 'Remote'
    case 'hybrid':
      return 'Hybrid'
    default:
      return '—'
  }
}

function formatDeadline(dateString: string | null): { label: string; tone: 'neutral' | 'warning' | 'error' } {
  if (!dateString) return { label: 'No deadline', tone: 'neutral' }
  const deadline = new Date(dateString)
  const daysLeft = Math.ceil((deadline.getTime() - Date.now()) / (1000 * 60 * 60 * 24))
  const formatted = deadline.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
  if (daysLeft < 0) return { label: `Closed ${formatted}`, tone: 'error' }
  if (daysLeft <= 3) return { label: `${formatted} · ${daysLeft === 0 ? 'today' : `${daysLeft}d left`}`, tone: 'warning' }
  return { label: formatted, tone: 'neutral' }
}

function extractErrorMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const message = (error.response?.data as { message?: string } | undefined)?.message
    if (message) return message
  }
  return fallback
}

interface OpportunitiesPageProps {
  type: OpportunityType
}

/**
 * Shared implementation behind BOTH the Jobs and Internships nav
 * pages - the backend is one union'd endpoint
 * (Api\Student\OpportunityController) differentiated only by a `type`
 * param, so this mirrors the NotificationsPage precedent (one real
 * page, thin per-route wrappers) rather than two parallel builds.
 *
 * DISCREPANCY FROM THE ORIGINAL SPEC, kept in favor of the real
 * backend per this build-out's ground rules: the listing endpoint has
 * an explicit "PRODUCT RULE - do not weaken this" that it never
 * filters or marks rows by eligibility - every published opportunity
 * shows to every student, full stop, and OpportunityListResource (the
 * row shape) carries no eligibility field to mark rows with even if
 * we wanted to. Eligibility (both the admin-configured criteria and
 * this student's own evaluation against it) only exists on the
 * single-opportunity detail payload. So: no eligibility filter/marker
 * in the list below, but the detail Modal below states it clearly.
 */
export function OpportunitiesPage({ type }: OpportunitiesPageProps) {
  const noun = type === 'internship' ? 'internship' : 'job'
  const nounPlural = type === 'internship' ? 'internships' : 'jobs'

  const [search, setSearch] = useState('')
  const [location, setLocation] = useState('')
  const [workMode, setWorkMode] = useState<WorkMode | 'all'>('all')
  const [page, setPage] = useState(1)

  const [items, setItems] = useState<OpportunityListItem[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const [selected, setSelected] = useState<OpportunityListItem | null>(null)
  const [detail, setDetail] = useState<OpportunityDetail | null>(null)
  const [isLoadingDetail, setIsLoadingDetail] = useState(false)
  const [detailError, setDetailError] = useState(false)

  const [confirmingApply, setConfirmingApply] = useState(false)
  const [isApplying, setIsApplying] = useState(false)
  const [applyError, setApplyError] = useState<string | null>(null)

  const hasActiveFilters = search.trim() !== '' || location.trim() !== '' || workMode !== 'all'

  const load = useCallback(() => {
    setIsLoading(true)
    setLoadError(false)
    opportunityService
      .list({
        type,
        search: search.trim() || undefined,
        location: location.trim() || undefined,
        work_mode: workMode === 'all' ? undefined : workMode,
        page,
        per_page: 15,
      })
      .then((data) => {
        setItems(data.items)
        setMeta(data.meta)
      })
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }, [type, search, location, workMode, page])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    // Reset filters/page when switching between Jobs and Internships (two routes, one component instance is remounted by the router, but this guards against any future shared-instance use).
    setPage(1)
  }, [type])

  useEffect(() => {
    if (!selected) {
      setDetail(null)
      return
    }
    setIsLoadingDetail(true)
    setDetailError(false)
    const request = type === 'internship' ? opportunityService.showInternship(selected.id) : opportunityService.showJob(selected.id)
    request
      .then(setDetail)
      .catch(() => setDetailError(true))
      .finally(() => setIsLoadingDetail(false))
  }, [selected, type])

  function resetFilters() {
    setSearch('')
    setLocation('')
    setWorkMode('all')
    setPage(1)
  }

  function handleApply() {
    if (!selected) return
    setIsApplying(true)
    setApplyError(null)
    const request =
      type === 'internship' ? opportunityService.applyToInternship(selected.id) : opportunityService.applyToJob(selected.id)
    request
      .then(() => {
        toast('success', `Application submitted for ${selected.title}.`)
        setConfirmingApply(false)
        setSelected(null)
      })
      .catch((error) => {
        setApplyError(extractErrorMessage(error, `Couldn't submit your application. Please try again.`))
      })
      .finally(() => setIsApplying(false))
  }

  const typeSpecificColumn =
    type === 'internship'
      ? {
          key: 'internship-terms',
          header: 'Stipend / Duration',
          render: (row: OpportunityListItem) => (
            <span className="text-ink-soft">
              {row.stipend ? `₹${row.stipend}/mo` : 'Unpaid'}
              {row.duration_months ? ` · ${row.duration_months} mo` : ''}
            </span>
          ),
        }
      : {
          key: 'job-terms',
          header: 'Salary',
          render: (row: OpportunityListItem) => <span className="text-ink-soft">{row.salary_range ?? '—'}</span>,
        }

  return (
    <div className="space-y-6">
      <SectionHeader
        title={type === 'internship' ? 'Internships' : 'Jobs'}
        subtitle={`Every published ${noun} opening, open to every student — eligibility criteria appear once you open one.`}
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <SearchBar
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder={`Search ${nounPlural} by title...`}
          className="sm:max-w-xs"
        />
        <Input
          value={location}
          onChange={(event) => {
            setLocation(event.target.value)
            setPage(1)
          }}
          placeholder="Location"
          className="sm:max-w-[10rem]"
          aria-label="Filter by location"
        />
        <Select
          value={workMode}
          onChange={(event) => {
            setWorkMode(event.target.value as WorkMode | 'all')
            setPage(1)
          }}
          className="sm:max-w-[10rem]"
          aria-label="Filter by work mode"
        >
          {WORK_MODE_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </div>

      {isLoading ? (
        <div className="card p-5">
          <LoadingSkeletonLines count={5} className="h-16 w-full" />
        </div>
      ) : loadError ? (
        <div className="card">
          <EmptyState
            icon={AlertTriangle}
            title={`Couldn't load ${nounPlural}`}
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
              title={`No ${nounPlural} match your filters`}
              description="Try a different location, work mode, or search term."
              action={
                <Button variant="secondary" onClick={resetFilters}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={type === 'internship' ? GraduationCap : Briefcase}
              title={`No ${nounPlural} published yet`}
              description="Check back soon — new opportunities are posted regularly."
            />
          )}
        </div>
      ) : (
        <>
          <DataTable
            columns={[
              {
                key: 'opportunity',
                header: type === 'internship' ? 'Internship' : 'Job',
                render: (row) => (
                  <div className="min-w-0">
                    <p className="truncate font-medium text-ink">{row.title}</p>
                    <p className="truncate text-xs text-ink-soft">{row.company?.name ?? '—'}</p>
                  </div>
                ),
              },
              {
                key: 'location',
                header: 'Location',
                render: (row) => (
                  <span className="text-ink-soft">
                    {row.location ?? '—'} · {formatWorkMode(row.work_mode)}
                  </span>
                ),
              },
              typeSpecificColumn,
              {
                key: 'deadline',
                header: 'Apply by',
                render: (row) => {
                  const { label, tone } = formatDeadline(row.application_deadline)
                  return <StatusBadge tone={tone}>{label}</StatusBadge>
                },
              },
            ]}
            rows={items}
            rowKey={(row) => row.id}
            onRowClick={(row) => setSelected(row)}
            renderMobileCard={(row) => {
              const { label, tone } = formatDeadline(row.application_deadline)
              return (
                <div className="space-y-2">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate font-medium text-ink">{row.title}</p>
                      <p className="truncate text-xs text-ink-soft">{row.company?.name ?? '—'}</p>
                    </div>
                    <StatusBadge tone={tone}>{label}</StatusBadge>
                  </div>
                  <p className="text-xs text-ink-faint">
                    {row.location ?? '—'} · {formatWorkMode(row.work_mode)}
                  </p>
                </div>
              )
            }}
          />

          {meta && meta.last_page > 1 && <Pagination page={meta.current_page} totalPages={meta.last_page} onChange={setPage} />}
        </>
      )}

      <Modal open={selected !== null} onClose={() => setSelected(null)} title={selected?.title ?? ''}>
        {isLoadingDetail || !detail ? (
          detailError ? (
            <EmptyState icon={AlertTriangle} title="Couldn't load this opportunity" description="Something went wrong reaching the server." />
          ) : (
            <LoadingSkeletonLines count={5} className="h-4 w-full" />
          )
        ) : (
          <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-2 text-xs text-ink-soft">
              <span>{detail.company?.name}</span>
              {detail.company?.industry && <span>· {detail.company.industry}</span>}
              <span>· {detail.location ?? 'Location unspecified'}</span>
              <span>· {formatWorkMode(detail.work_mode)}</span>
            </div>

            <p className="whitespace-pre-line text-sm text-ink">{detail.description}</p>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <dt className="text-ink-soft">Apply by</dt>
              <dd className="text-ink">{detail.application_deadline ?? 'No deadline'}</dd>
              <dt className="text-ink-soft">Openings</dt>
              <dd className="text-ink">{detail.openings ?? '—'}</dd>
              {detail.type === 'job' ? (
                <>
                  <dt className="text-ink-soft">Employment type</dt>
                  <dd className="text-ink">{detail.employment_type ?? '—'}</dd>
                  <dt className="text-ink-soft">Salary</dt>
                  <dd className="text-ink">{detail.salary_range ?? '—'}</dd>
                </>
              ) : (
                <>
                  <dt className="text-ink-soft">Duration</dt>
                  <dd className="text-ink">{detail.duration_months ? `${detail.duration_months} months` : '—'}</dd>
                  <dt className="text-ink-soft">Stipend</dt>
                  <dd className="text-ink">{detail.stipend ? `₹${detail.stipend}/mo` : 'Unpaid'}</dd>
                </>
              )}
            </dl>

            <div className="rounded-lg border border-hairline p-3">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">Eligibility</p>
              {!detail.eligibility.enforced ? (
                <p className="text-sm text-ink-soft">No eligibility criteria configured — open to every student.</p>
              ) : (
                <div className="space-y-2 text-sm">
                  <ul className="list-inside list-disc text-ink-soft">
                    {detail.eligibility.minimum_cgpa !== null && <li>Minimum CGPA {detail.eligibility.minimum_cgpa.toFixed(2)}</li>}
                    {detail.eligibility.maximum_backlogs !== null && <li>Maximum {detail.eligibility.maximum_backlogs} backlogs</li>}
                    {detail.eligibility.departments.length > 0 && (
                      <li>Departments: {detail.eligibility.departments.map((d) => d.name).join(', ')}</li>
                    )}
                    {detail.eligibility.batches.length > 0 && <li>Batches: {detail.eligibility.batches.map((b) => b.name).join(', ')}</li>}
                    {detail.eligibility.years.length > 0 && <li>Years: {detail.eligibility.years.join(', ')}</li>}
                    {detail.eligibility.required_skills.length > 0 && (
                      <li>Required skills: {detail.eligibility.required_skills.join(', ')}</li>
                    )}
                  </ul>

                  {detail.my_eligibility.eligible ? (
                    <StatusBadge tone="success">You're eligible</StatusBadge>
                  ) : (
                    <div className="space-y-1">
                      <StatusBadge tone="error">You don't currently meet every criterion</StatusBadge>
                      <ul className="list-inside list-disc text-ink-soft">
                        {detail.my_eligibility.reasons.map((reason) => (
                          <li key={reason.code}>{reason.message}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        )}

        {detail && (
          <div className="mt-6 flex justify-end">
            <Button onClick={() => setConfirmingApply(true)}>Apply</Button>
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={confirmingApply}
        onClose={() => {
          setConfirmingApply(false)
          setApplyError(null)
        }}
        onConfirm={handleApply}
        title={`Apply to ${selected?.title ?? `this ${noun}`}?`}
        description={`This submits your application to ${selected?.company?.name ?? 'the company'}. You can withdraw later from Applications if you change your mind.`}
        confirmLabel="Submit application"
        isConfirming={isApplying}
      >
        {applyError && <p className="mt-3 rounded-lg bg-error-bg p-3 text-sm text-error">{applyError}</p>}
      </ConfirmDialog>
    </div>
  )
}
