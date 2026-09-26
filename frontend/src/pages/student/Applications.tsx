import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { SlidersHorizontal } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { Tabs } from '@/components/ui/Tabs'
import { SearchBar } from '@/components/ui/SearchBar'
import { FilterChip } from '@/components/ui/FilterChip'
import { Drawer } from '@/components/ui/Drawer'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { MetricBand, type Metric } from '@/components/dashboard/MetricBand'
import { ApplicationRow } from '@/components/applications/ApplicationRow'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { staggerDelay } from '@/styles/motion'
import { applicationService } from '@/services/applicationService'
import type { ApplicationListItem, ApplicationStatus } from '@/types/application'
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

interface CareerMetrics {
  total: number
  underReview: number
  shortlisted: number
  interview: number
  selected: number
}

/**
 * Frontend Phase 3 - My Applications. Rebuilt from the DataTable-based
 * version (Phase 1/2 era) into the "career tracker" list this phase's
 * spec asks for: a MetricBand strip (the same primitive the Student
 * Dashboard already uses for its own stats, not the boxed StatCard,
 * per the design system's "never 4 identical boxed stat cards" rule -
 * this page is Student-only, so it follows that same convention), a
 * Tabs + Select/Drawer filter bar mirroring OpportunitiesPage exactly
 * (Frontend Phase 2), and ApplicationRow instead of DataTable rows.
 * Row selection now navigates to a dedicated /student/applications/:id
 * route (see pages/student/ApplicationDetail.tsx) instead of opening a
 * Modal, mirroring the OpportunityDetailPage precedent from Phase 2 -
 * needed here for the Interview/Internship cross-navigation actions a
 * detail page can carry that a Modal can't route out of cleanly.
 *
 * "Active" is intentionally not offered as its own status filter chip:
 * ApplicationController::index() only accepts a single exact `status`
 * value (checked against the real controller), and "Active" would
 * mean several statuses at once - building that would mean either an
 * unsupported multi-value backend param or a client-side filter that
 * silently breaks real server-side pagination (a page can come back
 * with fewer than per_page rows after filtering). Every real backend
 * status remains filterable via the Status select/drawer below, which
 * already covers more ground than the spec's suggested minimum.
 */
export function StudentApplicationsPage() {
  const navigate = useNavigate()

  const [status, setStatus] = useState<ApplicationStatus | 'all'>('all')
  const [type, setType] = useState<TypeFilter>('all')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [filtersOpen, setFiltersOpen] = useState(false)

  const [items, setItems] = useState<ApplicationListItem[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const [metrics, setMetrics] = useState<CareerMetrics | null>(null)

  const hasActiveFilters = status !== 'all' || search.trim() !== ''
  const mobileFilterCount = status !== 'all' ? 1 : 0

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

  // Career metrics strip (section 5): five lightweight requests, each
  // read only for its meta.total (the real, server-computed count
  // across ALL matching rows, not just one page) - not a second
  // analytics endpoint, and not capped by the 100-row bound a single
  // big fetch would need, so the numbers stay accurate regardless of
  // how many applications a student has.
  useEffect(() => {
    Promise.all([
      applicationService.list({ per_page: 1 }),
      applicationService.list({ status: 'UNDER_REVIEW', per_page: 1 }),
      applicationService.list({ status: 'SHORTLISTED', per_page: 1 }),
      applicationService.list({ status: 'INTERVIEW', per_page: 1 }),
      applicationService.list({ status: 'SELECTED', per_page: 1 }),
    ])
      .then(([total, underReview, shortlisted, interview, selected]) => {
        setMetrics({
          total: total.meta.total,
          underReview: underReview.meta.total,
          shortlisted: shortlisted.meta.total,
          interview: interview.meta.total,
          selected: selected.meta.total,
        })
      })
      .catch(() => setMetrics(null))
  }, [])

  function resetFilters() {
    setStatus('all')
    setSearch('')
    setPage(1)
  }

  const metricItems: Metric[] = metrics
    ? [
        { label: 'Total Applications', value: metrics.total },
        { label: 'Under Review', value: metrics.underReview },
        { label: 'Shortlisted', value: metrics.shortlisted },
        { label: 'Interviews', value: metrics.interview },
        { label: 'Selected', value: metrics.selected },
      ]
    : []

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="My Applications" description="Track your applications and see where you are in each opportunity." />

      {metrics && <MetricBand metrics={metricItems} />}

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

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <SearchBar
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search by opportunity or company..."
          className="sm:max-w-xs"
        />

        <div className="hidden sm:block">
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as ApplicationStatus | 'all')
              setPage(1)
            }}
            className="sm:max-w-[11rem]"
            aria-label="Filter by status"
          >
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </div>

        <button type="button" onClick={() => setFiltersOpen(true)} className="btn-secondary sm:hidden">
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {mobileFilterCount > 0 && (
            <span className="ml-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-[color:var(--color-primary-ink)]">
              {mobileFilterCount}
            </span>
          )}
        </button>
      </div>

      {hasActiveFilters && (
        <div className="flex flex-wrap items-center gap-2">
          {search.trim() && <FilterChip label={`"${search.trim()}"`} onRemove={() => setSearch('')} />}
          {status !== 'all' && (
            <FilterChip
              label={STATUS_OPTIONS.find((option) => option.value === status)?.label ?? status}
              onRemove={() => setStatus('all')}
            />
          )}
          <button type="button" onClick={resetFilters} className="text-xs font-medium text-muted hover:text-ink">
            Clear all
          </button>
        </div>
      )}

      {isLoading ? (
        <div className="card p-5">
          <LoadingSkeletonLines count={5} className="h-16 w-full" />
        </div>
      ) : loadError ? (
        <div className="card">
          <ErrorState title="Unable to load applications" onRetry={load} />
        </div>
      ) : items.length === 0 ? (
        <div className="card">
          {hasActiveFilters ? (
            <EmptyState
              illustration={<EmptySearchIllustration />}
              title="No applications match your filters"
              description="Try a different status or search term."
              action={
                <Button variant="secondary" onClick={resetFilters}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No applications yet"
              description="Your next opportunity could start here."
              action={
                <div className="flex flex-wrap justify-center gap-2">
                  <Link to="/student/jobs">
                    <Button>Explore Jobs</Button>
                  </Link>
                  <Link to="/student/internships">
                    <Button variant="secondary">Explore Internships</Button>
                  </Link>
                </div>
              }
            />
          )}
        </div>
      ) : (
        <>
          <div className="space-y-3">
            {items.map((item, index) => (
              <ApplicationRow
                key={item.id}
                item={item}
                onSelect={(row) => navigate(`/student/applications/${row.id}`)}
                style={staggerDelay(index)}
              />
            ))}
          </div>

          {meta && meta.last_page > 1 && <Pagination page={meta.current_page} totalPages={meta.last_page} onChange={setPage} />}
        </>
      )}

      <Drawer
        open={filtersOpen}
        onClose={() => setFiltersOpen(false)}
        title="Filters"
        footer={<Button onClick={() => setFiltersOpen(false)}>Done</Button>}
      >
        <div className="space-y-4">
          <div>
            <label className="label" htmlFor="mobile-status">
              Status
            </label>
            <Select
              id="mobile-status"
              value={status}
              onChange={(event) => {
                setStatus(event.target.value as ApplicationStatus | 'all')
                setPage(1)
              }}
            >
              {STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>
          {mobileFilterCount > 0 && (
            <button
              type="button"
              onClick={() => {
                setStatus('all')
                setPage(1)
              }}
              className="text-xs font-medium text-muted hover:text-ink"
            >
              Clear filters
            </button>
          )}
        </div>
      </Drawer>
    </div>
  )
}
