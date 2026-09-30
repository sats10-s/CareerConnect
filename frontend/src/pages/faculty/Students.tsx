import { useEffect, useState } from 'react'

import { PageHeader } from '@/components/ui/PageHeader'
import { SearchBar } from '@/components/ui/SearchBar'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { InternshipCell, MetaBadge } from '@/components/common/Cells'
import { Avatar } from '@/components/ui/Avatar'
import { AssignmentDrawer } from '@/components/faculty/AssignmentDrawer'
import { FacultyAssignmentLinks } from '@/components/faculty/AssignmentLinks'
import { useApiList } from '@/hooks/useApiList'
import { facultyService } from '@/services/facultyService'
import { metaService } from '@/services/metaService'
import { ASSIGNMENT_STATUS, optionsFor } from '@/utils/statusMeta'
import type { FacultyStudentItem } from '@/types/coordination'
import type { InternshipAssignmentStatus } from '@/types/internshipAssignment'
import type { Department } from '@/types/meta'

const YEAR_OPTIONS = [1, 2, 3, 4].map((year) => ({ value: year, label: `Year ${year}` }))

/**
 * Faculty > My Students - GET /api/faculty/students. The backend
 * returns only students with an internship assignment to the signed-in
 * faculty member (FacultyStudentController scopes by the caller's own
 * faculty record); nothing here filters by faculty.
 */
export function FacultyStudentsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [year, setYear] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [departments, setDepartments] = useState<Department[]>([])
  const [openAssignmentId, setOpenAssignmentId] = useState<number | null>(null)

  useEffect(() => {
    metaService.departments().then(setDepartments).catch(() => setDepartments([]))
  }, [])

  const list = useApiList(facultyService.students, {
    search: search.trim() || undefined,
    status: (status || undefined) as InternshipAssignmentStatus | undefined,
    year: year ? Number(year) : undefined,
    department_id: departmentId ? Number(departmentId) : undefined,
  })

  const hasFilters = Boolean(search.trim() || status || year || departmentId)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
    setYear('')
    setDepartmentId('')
  }

  const columns = [
    {
      key: 'student',
      header: 'Student',
      render: (row: FacultyStudentItem) => (
        <div className="flex min-w-0 items-center gap-3">
          <Avatar name={row.name} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{row.name}</p>
            <p className="truncate text-xs text-ink-faint">{row.roll_number} · {row.email}</p>
          </div>
        </div>
      ),
    },
    { key: 'department', header: 'Department', render: (row: FacultyStudentItem) => <span className="text-sm text-ink-soft">{row.department ?? '—'}</span> },
    { key: 'year', header: 'Year', render: (row: FacultyStudentItem) => (row.year ? `Year ${row.year}` : '—') },
    { key: 'internship', header: 'Internship', render: (row: FacultyStudentItem) => <InternshipCell internship={row.assignment?.internship} /> },
    { key: 'status', header: 'Status', render: (row: FacultyStudentItem) => <MetaBadge map={ASSIGNMENT_STATUS} value={row.assignment?.status} /> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="My Students" description="Students the placement office has assigned to you as their internship coordinator." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by name or roll number" className="w-full sm:w-80" />
        <FilterSelect label="Internship status" value={status} onChange={setStatus} options={optionsFor(ASSIGNMENT_STATUS)} allLabel="All statuses" />
        <FilterSelect label="Year" value={year} onChange={setYear} options={YEAR_OPTIONS} allLabel="All years" />
        <FilterSelect
          label="Department"
          value={departmentId}
          onChange={setDepartmentId}
          options={departments.map((d) => ({ value: d.id, label: d.code }))}
          allLabel="All departments"
        />
        {hasFilters && (
          <Button variant="secondary" onClick={clearFilters}>
            Clear filters
          </Button>
        )}
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
              title="No students match these filters"
              description="Try a different name, year or department, or clear the filters to see everyone assigned to you."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No students assigned to you yet"
              description="Students show up here once the placement office assigns you as their internship faculty coordinator."
            />
          )
        }
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={(row) => row.assignment && setOpenAssignmentId(row.assignment.id)}
          renderMobileCard={(row) => (
            <div className="space-y-3">
              <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                  <Avatar name={row.name} size="sm" />
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">{row.name}</p>
                    <p className="truncate text-xs text-ink-faint">{row.roll_number}{row.year ? ` · Year ${row.year}` : ''}</p>
                  </div>
                </div>
                <MetaBadge map={ASSIGNMENT_STATUS} value={row.assignment?.status} />
              </div>
              <InternshipCell internship={row.assignment?.internship} />
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <AssignmentDrawer
        assignmentId={openAssignmentId}
        onClose={() => setOpenAssignmentId(null)}
        loader={facultyService.assignment}
        footer={(assignment) => <FacultyAssignmentLinks assignmentId={assignment.id} />}
      />
    </div>
  )
}
