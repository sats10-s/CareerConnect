import { useState } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { Input } from '@/components/ui/Input'
import { backlogsMeta } from '@/components/profile/college/collegeFormat'
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
const SECTIONS = ['A', 'B', 'C', 'D'].map((x) => ({ value: x, label: `Section ${x}` }))
const BACKLOGS = [
  { value: 'none', label: 'No backlogs' },
  { value: 'some', label: '1–2 backlogs' },
  { value: 'many', label: '3+ backlogs' },
  { value: 'not_reported', label: 'Not reported' },
]
const ENTRY = [
  { value: 'regular', label: 'Regular' },
  { value: 'lateral', label: 'Lateral entry' },
]
const JOINING = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i).map((y) => ({ value: y, label: `Joined ${y}` }))
const PLACEMENT = [
  { value: 'placed', label: 'Placed' },
  { value: 'unplaced', label: 'Not placed yet' },
]

/** Only a 0-10 number is sent; anything else is ignored rather than rejected while typing. */
function validCgpa(value: string): number | undefined {
  const n = Number(value)
  return value.trim() !== '' && Number.isFinite(n) && n >= 0 && n <= 10 ? n : undefined
}

function StudentCell({ row }: { row: AdminStudentRow }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Avatar name={row.name ?? '?'} size="sm" />
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{row.name}</p>
        <p className="truncate text-xs text-ink-faint">{[row.roll_number, row.email].filter(Boolean).join(' · ')}</p>
      </div>
    </div>
  )
}

function PlacementBadge({ row }: { row: AdminStudentRow }) {
  if (row.placement_status === 'PLACED') return <StatusBadge tone="success">Placed</StatusBadge>
  if (row.placement_status === 'NOT_PLACED' || row.placement_status === 'UNPLACED') return <StatusBadge tone="info">Not placed</StatusBadge>
  if (row.placement_status === 'UNKNOWN') return <StatusBadge>Unconfirmed</StatusBadge>
  return row.applications_count > 0 ? <StatusBadge tone="info">Applying</StatusBadge> : <StatusBadge>Not applied</StatusBadge>
}

/**
 * Placement Admin > Students - GET /admin/students (search, department,
 * batch, year, placement). Plus college-record filters
 * (section, backlogs, CGPA range, entry type, year of joining, skill).
 * Records come from Super Admin > Student Data; opening a row shows the full profile, applications and
 * internship assignments.
 */
export function AdminStudentsPage() {
  const [search, setSearch] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [batchId, setBatchId] = useState('')
  const [year, setYear] = useState('')
  const [placement, setPlacement] = useState('')
  const [section, setSection] = useState('')
  const [backlogs, setBacklogs] = useState('')
  const [entryType, setEntryType] = useState('')
  const [joining, setJoining] = useState('')
  const [cgpaMin, setCgpaMin] = useState('')
  const [cgpaMax, setCgpaMax] = useState('')
  const [skill, setSkill] = useState('')
  const [moreOpen, setMoreOpen] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)
  const departments = useApiResource(() => metaService.departments(), 'departments')
  const batches = useApiResource(() => metaService.batches(), 'batches')

  const list = useApiList(adminService.students.list, {
    search: search.trim() || undefined,
    department_id: departmentId ? Number(departmentId) : undefined,
    batch_id: batchId ? Number(batchId) : undefined,
    year: year ? Number(year) : undefined,
    placement: (placement || undefined) as 'placed' | 'unplaced' | undefined,
    section: section || undefined,
    backlogs: (backlogs || undefined) as 'none' | 'some' | 'many' | 'not_reported' | undefined,
    entry_type: (entryType || undefined) as 'regular' | 'lateral' | undefined,
    year_of_joining: joining ? Number(joining) : undefined,
    cgpa_min: validCgpa(cgpaMin),
    cgpa_max: validCgpa(cgpaMax),
    skill: skill.trim() || undefined,
  })

  const moreActive = Boolean(section || backlogs || entryType || joining || cgpaMin || cgpaMax || skill.trim())
  const hasFilters = Boolean(search.trim() || departmentId || batchId || year || placement || moreActive)
  const clearFilters = () => {
    setSection('')
    setBacklogs('')
    setEntryType('')
    setJoining('')
    setCgpaMin('')
    setCgpaMax('')
    setSkill('')
    setSearch('')
    setDepartmentId('')
    setBatchId('')
    setYear('')
    setPlacement('')
  }

  const columns = [
    { key: 'student', header: 'Student', render: (row: AdminStudentRow) => <StudentCell row={row} /> },
    { key: 'dept', header: 'Department', render: (row: AdminStudentRow) => <div><p className="text-sm text-ink">{row.department ?? '—'}</p><p className="text-xs text-ink-faint">{row.batch ?? ''}{row.year ? ` · Year ${row.year}` : ''}</p></div> },
    { key: 'section', header: 'Section', render: (row: AdminStudentRow) => <span>{row.section ?? '—'}{row.entry_type === 'lateral' ? <span className="ml-1 text-xs text-ink-faint">· Lateral</span> : null}</span> },
    { key: 'cgpa', header: 'CGPA', render: (row: AdminStudentRow) => row.cgpa !== null ? <span className="tabular-nums">{row.cgpa.toFixed(2)}</span> : <span className="text-xs text-ink-faint">Not available</span> },
    { key: 'backlogs', header: 'Backlogs', render: (row: AdminStudentRow) => { const m = backlogsMeta(row.backlogs); return <StatusBadge tone={m.tone}>{row.backlogs === null ? 'Not reported' : String(row.backlogs)}</StatusBadge> } },
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
        <Button variant="secondary" onClick={() => setMoreOpen((o) => !o)} aria-expanded={moreOpen}>
          <SlidersHorizontal className="h-4 w-4" /> More filters{moreActive ? ' •' : ''}
        </Button>
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
      </FilterBar>

      {moreOpen && (
        <div className="card mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
          <FilterSelect label="Section" value={section} onChange={setSection} options={SECTIONS} allLabel="All sections" />
          <FilterSelect label="Backlogs" value={backlogs} onChange={setBacklogs} options={BACKLOGS} allLabel="Any backlogs" />
          <FilterSelect label="Entry type" value={entryType} onChange={setEntryType} options={ENTRY} allLabel="Regular and lateral" />
          <FilterSelect label="Year of joining" value={joining} onChange={setJoining} options={JOINING} allLabel="Any joining year" />
          <div className="flex items-center gap-2">
            <Input aria-label="Minimum CGPA" type="number" inputMode="decimal" min={0} max={10} step={0.1} placeholder="CGPA from" value={cgpaMin} onChange={(e) => setCgpaMin(e.target.value)} />
            <span className="text-ink-faint">–</span>
            <Input aria-label="Maximum CGPA" type="number" inputMode="decimal" min={0} max={10} step={0.1} placeholder="to" value={cgpaMax} onChange={(e) => setCgpaMax(e.target.value)} />
          </div>
          <Input aria-label="Skill" placeholder="Has skill, e.g. React" value={skill} onChange={(e) => setSkill(e.target.value)} maxLength={40} />
        </div>
      )}

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
              <p className="text-xs text-ink-faint">{[row.department, row.section ? `Sec ${row.section}` : null, row.year ? `Year ${row.year}` : null].filter(Boolean).join(' · ')} · CGPA {row.cgpa !== null ? row.cgpa.toFixed(2) : 'n/a'} · {backlogsMeta(row.backlogs).label} · {row.applications_count} application{row.applications_count === 1 ? '' : 's'}</p>
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
