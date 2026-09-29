import { useState } from 'react'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { DetailItem, InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { AssignmentDrawer } from '@/components/faculty/AssignmentDrawer'
import { AssignmentActions } from './AssignmentActions'
import { AssignInternModal } from './AssignInternModal'
import { useFacultyOptions } from '@/hooks/useAdminOptions'
import { useApiList } from '@/hooks/useApiList'
import { adminService } from '@/services/adminService'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import { ASSIGNMENT_STATUS, optionsFor } from '@/utils/statusMeta'
import type { AssignmentListItem } from '@/types/coordination'
import type { InternshipAssignmentStatus } from '@/types/internshipAssignment'

/** Admin view of internship assignments: assign a selected student to a faculty coordinator, then run the assignment's lifecycle. */
export function AssignmentList() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [facultyId, setFacultyId] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const faculty = useFacultyOptions()

  const list = useApiList(adminService.assignments.list, {
    search: search.trim() || undefined,
    status: (status || undefined) as InternshipAssignmentStatus | undefined,
    faculty_id: facultyId ? Number(facultyId) : undefined,
  })

  const hasFilters = Boolean(search.trim() || status || facultyId)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
    setFacultyId('')
  }

  const columns = [
    { key: 'student', header: 'Student', render: (row: AssignmentListItem) => <PersonCell student={row.student} /> },
    { key: 'internship', header: 'Internship', render: (row: AssignmentListItem) => <InternshipCell internship={row.internship} /> },
    { key: 'faculty', header: 'Coordinator', render: (row: AssignmentListItem) => row.faculty?.name ?? '—' },
    { key: 'status', header: 'Status', render: (row: AssignmentListItem) => <MetaBadge map={ASSIGNMENT_STATUS} value={row.status} /> },
    { key: 'started', header: 'Started', render: (row: AssignmentListItem) => formatDate(row.started_at) },
  ]

  return (
    <div>
      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by student, faculty or company" className="w-full sm:w-72" />
        <FilterSelect label="Assignment status" value={status} onChange={setStatus} options={optionsFor(ASSIGNMENT_STATUS)} allLabel="All statuses" />
        <FilterSelect label="Coordinator" value={facultyId} onChange={setFacultyId} options={(faculty.data ?? []).map((f) => ({ value: f.faculty_id, label: f.faculty_name }))} allLabel="All faculty" />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
        <div className="w-full sm:ml-auto sm:w-auto">
          <Button onClick={() => setCreating(true)} className="w-full sm:w-auto">
            <Plus className="h-4 w-4" />
            Assign a student
          </Button>
        </div>
      </FilterBar>

      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={list.items.length === 0}
        hasLoadedOnce={list.meta !== null}
        onRetry={list.reload}
        empty={
          hasFilters ? (
            <EmptyState illustration={<EmptySearchIllustration />} title="No assignments match these filters" description="Try another status or coordinator, or clear the filters." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No internship assignments yet"
              description="Once a student's internship application is marked Selected, assign them a faculty coordinator here."
              action={<Button onClick={() => setCreating(true)}><Plus className="h-4 w-4" />Assign a student</Button>}
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
              <p className="text-xs text-ink-faint">Coordinator: {row.faculty?.name ?? '—'}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      {creating && <AssignInternModal onClose={() => setCreating(false)} onSaved={(id) => { list.reload(); setOpenId(id) }} />}
      <AssignmentDrawer
        assignmentId={openId}
        onClose={() => setOpenId(null)}
        loader={adminService.assignments.show}
        extra={(a) => (
          <>
            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Assigned by">{a.assigned_by?.name}</DetailItem>
              <DetailItem label="Created">{formatDateTime(a.created_at ?? null)}</DetailItem>
              <div className="col-span-2"><DetailItem label="Notes">{a.notes}</DetailItem></div>
            </dl>
            {a.faculty_history.length > 0 && (
              <section>
                <h3 className="mb-2 text-sm font-semibold text-ink">Coordinator history</h3>
                <ul className="space-y-2 text-sm">
                  {a.faculty_history.map((h) => (
                    <li key={h.id} className="rounded-lg bg-sidebar p-3">
                      <p className="text-ink">{h.from_faculty?.name ?? '—'} → {h.to_faculty?.name ?? '—'}</p>
                      <p className="text-xs text-ink-faint">{formatDateTime(h.created_at)}{h.changed_by ? ` · ${h.changed_by.name}` : ''}{h.remarks ? ` · ${h.remarks}` : ''}</p>
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </>
        )}
        footer={(a, reload) => <AssignmentActions assignment={a} onChanged={() => { reload(); list.reload() }} />}
      />
    </div>
  )
}
