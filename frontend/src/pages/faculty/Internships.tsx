import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { SearchBar } from '@/components/ui/SearchBar'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { AssignmentDrawer } from '@/components/faculty/AssignmentDrawer'
import { FacultyAssignmentLinks } from '@/components/faculty/AssignmentLinks'
import { useApiList } from '@/hooks/useApiList'
import { facultyService, type FacultyAssignmentParams } from '@/services/facultyService'
import { formatDate } from '@/utils/dateFormat'
import { ASSIGNMENT_STATUS, optionsFor } from '@/utils/statusMeta'
import type { AssignmentListItem } from '@/types/coordination'
import type { InternshipAssignmentStatus } from '@/types/internshipAssignment'

const WHEN_OPTIONS = [
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'current', label: 'Running now' },
  { value: 'completed', label: 'Finished' },
]

/**
 * Faculty > Internships - GET /api/faculty/internship-assignments:
 * the internship placements this faculty member coordinates (one row
 * per assignment). Read-only by design - the backend gives faculty no
 * create/reassign/activate/complete/cancel routes; those are admin
 * actions (see routes/api.php's admin/internship-assignments group).
 */
export function FacultyInternshipsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [when, setWhen] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)

  const list = useApiList(facultyService.assignments, {
    search: search.trim() || undefined,
    status: (status || undefined) as InternshipAssignmentStatus | undefined,
    when: (when || undefined) as FacultyAssignmentParams['when'],
  })

  const hasFilters = Boolean(search.trim() || status || when)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
    setWhen('')
  }

  const columns = [
    { key: 'student', header: 'Student', render: (row: AssignmentListItem) => <PersonCell student={row.student} /> },
    { key: 'internship', header: 'Internship', render: (row: AssignmentListItem) => <InternshipCell internship={row.internship} /> },
    { key: 'status', header: 'Status', render: (row: AssignmentListItem) => <MetaBadge map={ASSIGNMENT_STATUS} value={row.status} /> },
    { key: 'started', header: 'Started', render: (row: AssignmentListItem) => formatDate(row.started_at) },
    { key: 'ended', header: 'Ended', render: (row: AssignmentListItem) => formatDate(row.ended_at) },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Internships" description="The internship placements you coordinate, one row per student." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by student or company" className="w-full sm:w-80" />
        <FilterSelect label="Status" value={status} onChange={setStatus} options={optionsFor(ASSIGNMENT_STATUS)} allLabel="All statuses" />
        <FilterSelect label="Timing" value={when} onChange={setWhen} options={WHEN_OPTIONS} allLabel="Any timing" />
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
            <EmptyState
              illustration={<EmptySearchIllustration />}
              title="No internships match these filters"
              description="Try a different search or timing, or clear the filters."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No internships to coordinate yet"
              description="When the placement office assigns you as the faculty coordinator for a selected student's internship, it will appear here."
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
            <div className="space-y-3">
              <div className="flex items-start justify-between gap-3">
                <PersonCell student={row.student} />
                <MetaBadge map={ASSIGNMENT_STATUS} value={row.status} />
              </div>
              <InternshipCell internship={row.internship} />
              <p className="text-xs text-ink-faint">Started {formatDate(row.started_at)}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <AssignmentDrawer
        assignmentId={openId}
        onClose={() => setOpenId(null)}
        loader={facultyService.assignment}
        footer={(assignment) => <FacultyAssignmentLinks assignmentId={assignment.id} />}
      />
    </div>
  )
}
