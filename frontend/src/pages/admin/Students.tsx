import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Avatar } from '@/components/ui/Avatar'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { StudentDrawer } from '@/components/admin/StudentDrawer'
import { useApiList } from '@/hooks/useApiList'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { metaService } from '@/services/metaService'
import type { AdminStudentRow } from '@/types/admin'

const YEARS = [1, 2, 3, 4].map((y) => ({ value: y, label: `Year ${y}` }))
const PLACEMENT = [
  { value: 'placed', label: 'Placed' },
  { value: 'unplaced', label: 'Not placed yet' },
]

function StudentCell({ row }: { row: AdminStudentRow }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Avatar name={row.name ?? '?'} size="sm" />
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{row.name}</p>
        <p className="truncate text-xs text-ink-faint">{row.roll_number} · {row.email}</p>
      </div>
    </div>
  )
}

function PlacementBadge({ row }: { row: AdminStudentRow }) {
  if (row.placement_status === 'SELECTED') return <StatusBadge tone="success">Placed</StatusBadge>
  return row.applications_count > 0 ? <StatusBadge tone="info">Applying</StatusBadge> : <StatusBadge>Not applied</StatusBadge>
}

/**
 * Placement Admin > Students - GET /admin/students (search, department,
 * batch, year, placement). Read-only: records come from registration and
 * Excel Sync; opening a row shows the full profile, applications and
 * internship assignments.
 */
export function AdminStudentsPage() {
  const [search, setSearch] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [batchId, setBatchId] = useState('')
  const [year, setYear] = useState('')
  const [placement, setPlacement] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const departments = useApiResource(() => metaService.departments(), 'departments')
  const batches = useApiResource(() => metaService.batches(), 'batches')

  const list = useApiList(adminService.students.list, {
    search: search.trim() || undefined,
    department_id: departmentId ? Number(departmentId) : undefined,
    batch_id: batchId ? Number(batchId) : undefined,
    year: year ? Number(year) : undefined,
    placement: (placement || undefined) as 'placed' | 'unplaced' | undefined,
  })

  const hasFilters = Boolean(search.trim() || departmentId || batchId || year || placement)
  const clearFilters = () => {
    setSearch('')
    setDepartmentId('')
    setBatchId('')
    setYear('')
    setPlacement('')
  }

  const columns = [
    { key: 'student', header: 'Student', render: (row: AdminStudentRow) => <StudentCell row={row} /> },
    { key: 'dept', header: 'Department', render: (row: AdminStudentRow) => <div><p className="text-sm text-ink">{row.department ?? '—'}</p><p className="text-xs text-ink-faint">{row.batch ?? ''}{row.year ? ` · Year ${row.year}` : ''}</p></div> },
    { key: 'cgpa', header: 'CGPA', render: (row: AdminStudentRow) => <span className="tabular-nums">{row.cgpa !== null ? row.cgpa.toFixed(2) : '—'}</span> },
    { key: 'apps', header: 'Applications', render: (row: AdminStudentRow) => <span className="tabular-nums">{row.applications_count}</span> },
    { key: 'placement', header: 'Placement', render: (row: AdminStudentRow) => <PlacementBadge row={row} /> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Students" description="Every registered student. Open one to see their profile, resume, applications and internship." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search name, roll no. or email" className="w-full sm:w-80" />
        <FilterSelect label="Department" value={departmentId} onChange={setDepartmentId} options={(departments.data ?? []).map((d) => ({ value: d.id, label: d.name }))} allLabel="All departments" />
        <FilterSelect label="Batch" value={batchId} onChange={setBatchId} options={(batches.data ?? []).map((b) => ({ value: b.id, label: b.name }))} allLabel="All batches" />
        <FilterSelect label="Year" value={year} onChange={setYear} options={YEARS} allLabel="All years" />
        <FilterSelect label="Placement" value={placement} onChange={setPlacement} options={PLACEMENT} allLabel="Any placement" />
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
            <EmptyState illustration={<EmptySearchIllustration />} title="No students match these filters" description="Try a different name or roll number, or clear the filters." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState illustration={<EmptyBriefcaseIllustration />} title="No students yet" description="Students appear here once they register, or after a Student master data import in Excel Sync." />
          )
        }
      >
        {list.meta && <p className="mb-3 text-xs text-ink-faint">{list.meta.total} student{list.meta.total === 1 ? '' : 's'}</p>}
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={(row) => setOpenId(row.id)}
          renderMobileCard={(row) => (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <StudentCell row={row} />
                <PlacementBadge row={row} />
              </div>
              <p className="text-xs text-ink-faint">{[row.department, row.batch, row.year ? `Year ${row.year}` : null].filter(Boolean).join(' · ')} · CGPA {row.cgpa !== null ? row.cgpa.toFixed(2) : '—'} · {row.applications_count} application{row.applications_count === 1 ? '' : 's'}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <StudentDrawer id={openId} onClose={() => setOpenId(null)} />
    </div>
  )
}
