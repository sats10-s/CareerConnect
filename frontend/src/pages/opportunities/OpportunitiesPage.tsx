import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, Briefcase, GraduationCap, SlidersHorizontal, SearchX } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { SearchBar } from '@/components/ui/SearchBar'
import { FilterChip } from '@/components/ui/FilterChip'
import { Drawer } from '@/components/ui/Drawer'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { OpportunityList } from '@/components/opportunities/OpportunityList'
import { opportunityService } from '@/services/opportunityService'
import { useAppliedOpportunities } from '@/hooks/useAppliedOpportunities'
import type { OpportunityListItem, OpportunityType, WorkMode } from '@/types/opportunity'
import type { PaginationMeta } from '@/types/common'

const WORK_MODE_OPTIONS: { value: WorkMode | 'all'; label: string }[] = [
  { value: 'all', label: 'Any work mode' },
  { value: 'on_site', label: 'On-site' },
  { value: 'remote', label: 'Remote' },
  { value: 'hybrid', label: 'Hybrid' },
]

/** Per-type copy from the Frontend Phase 2 spec (sections 5 and 10) - the two routes share this component but not this text. */
const HEADER_COPY: Record<OpportunityType, { title: string; description: string }> = {
  job: { title: 'Find Your Next Opportunity', description: 'Explore placement opportunities that match your career goals.' },
  internship: {
    title: 'Find an Internship That Builds Your Future',
    description: 'Explore internship opportunities and build real-world experience.',
  },
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
 * FILTERS ARE INTENTIONALLY LIMITED to what OpportunityController::
 * index() actually accepts (search/type/location/work_mode/page/
 * per_page - confirmed by reading it for Frontend Phase 2). No
 * company/department/salary/CGPA/graduation-year/deadline filter and
 * no sort param exist on the backend, so none are built here - the
 * spec itself says to use only backend-supported fields.
 *
 * DISCREPANCY FROM THE ORIGINAL SPEC, kept in favor of the real
 * backend per this build-out's ground rules: the listing endpoint has
 * an explicit "PRODUCT RULE - do not weaken this" that it never
 * filters or marks rows by eligibility - every published opportunity
 * shows to every student, full stop, and OpportunityListResource (the
 * row shape) carries no eligibility field to mark rows with even if
 * we wanted to. Eligibility only exists on the single-opportunity
 * detail payload (OpportunityDetailPage), which states it clearly.
 *
 * Selecting a row navigates to a real detail route (OpportunityDetailPage)
 * rather than opening a Modal, per Frontend Phase 2's dedicated detail-
 * page requirement.
 */
export function OpportunitiesPage({ type }: OpportunitiesPageProps) {
  const navigate = useNavigate()
  const nounPlural = type === 'internship' ? 'internships' : 'jobs'
  const header = HEADER_COPY[type]

  const [search, setSearch] = useState('')
  const [location, setLocation] = useState('')
  const [workMode, setWorkMode] = useState<WorkMode | 'all'>('all')
  const [page, setPage] = useState(1)
  const [filtersOpen, setFiltersOpen] = useState(false)

  const [items, setItems] = useState<OpportunityListItem[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const { statusFor: appliedStatusFor } = useAppliedOpportunities()

  const hasActiveFilters = search.trim() !== '' || location.trim() !== '' || workMode !== 'all'
  const mobileFilterCount = (location.trim() ? 1 : 0) + (workMode !== 'all' ? 1 : 0)

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

  function resetFilters() {
    setSearch('')
    setLocation('')
    setWorkMode('all')
    setPage(1)
  }

  function goToDetail(item: OpportunityListItem) {
    navigate(`/student/${item.type === 'internship' ? 'internships' : 'jobs'}/${item.id}`)
  }

  const workModeLabel = WORK_MODE_OPTIONS.find((option) => option.value === workMode)?.label

  return (
    <div className="space-y-6">
      <PageHeader title={header.title} description={header.description} />

      {/* Desktop/tablet: inline filter bar. Mobile: search stays visible, Location/Work mode move into a Drawer (per spec: "filter drawer / bottom sheet" on mobile instead of an inline bar). */}
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

        <div className="hidden sm:flex sm:flex-wrap sm:items-center sm:gap-3">
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

        <button
          type="button"
          onClick={() => setFiltersOpen(true)}
          className="btn-secondary sm:hidden"
        >
          <SlidersHorizontal className="h-4 w-4" />
          Filters
          {mobileFilterCount > 0 && (
            <span className="ml-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-white">
              {mobileFilterCount}
            </span>
          )}
        </button>
      </div>

      {hasActiveFilters && (
        <div className="flex flex-wrap items-center gap-2">
          {search.trim() && <FilterChip label={`"${search.trim()}"`} onRemove={() => setSearch('')} />}
          {location.trim() && <FilterChip label={location.trim()} onRemove={() => setLocation('')} />}
          {workMode !== 'all' && workModeLabel && <FilterChip label={workModeLabel} onRemove={() => setWorkMode('all')} />}
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
          <OpportunityList
            items={items}
            onSelect={goToDetail}
            appliedStatusFor={(item) => appliedStatusFor(item.type, item.id)}
          />

          {meta && meta.last_page > 1 && <Pagination page={meta.current_page} totalPages={meta.last_page} onChange={setPage} />}
        </>
      )}

      <Drawer open={filtersOpen} onClose={() => setFiltersOpen(false)} title="Filters" footer={<Button onClick={() => setFiltersOpen(false)}>Done</Button>}>
        <div className="space-y-4">
          <div>
            <label className="label" htmlFor="mobile-location">
              Location
            </label>
            <Input
              id="mobile-location"
              value={location}
              onChange={(event) => {
                setLocation(event.target.value)
                setPage(1)
              }}
              placeholder="Location"
            />
          </div>
          <div>
            <label className="label" htmlFor="mobile-work-mode">
              Work mode
            </label>
            <Select
              id="mobile-work-mode"
              value={workMode}
              onChange={(event) => {
                setWorkMode(event.target.value as WorkMode | 'all')
                setPage(1)
              }}
            >
              {WORK_MODE_OPTIONS.map((option) => (
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
                setLocation('')
                setWorkMode('all')
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
