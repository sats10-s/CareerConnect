import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { FileText } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { SearchBar } from '@/components/ui/SearchBar'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { MetaBadge, PersonCell } from '@/components/common/Cells'
import { DocumentDrawer } from '@/components/faculty/DocumentDrawer'
import { useApiList } from '@/hooks/useApiList'
import { useFacultyAssignments } from '@/hooks/useFacultyAssignments'
import { facultyService } from '@/services/facultyService'
import { formatDateTime, formatFileSize } from '@/utils/dateFormat'
import { DOCUMENT_STATUS, DOCUMENT_TYPE_LABEL, optionsFor } from '@/utils/statusMeta'
import type { DocumentRow } from '@/types/coordination'
import type { InternshipDocumentStatus, InternshipDocumentType } from '@/types/internshipDocument'

/**
 * Faculty > Documents: documents students upload for the internships
 * this faculty member coordinates (GET /faculty/internship-documents,
 * server-scoped). Faculty can download and review (status) - there is
 * no faculty upload route, so none is offered.
 */
export function FacultyDocumentsPage() {
  const [params, setParams] = useSearchParams()
  const assignmentParam = params.get('assignment') ?? ''
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const assignments = useFacultyAssignments()

  const list = useApiList(facultyService.documents, {
    internship_assignment_id: assignmentParam ? Number(assignmentParam) : undefined,
    status: (status || undefined) as InternshipDocumentStatus | undefined,
    document_type: (type || undefined) as InternshipDocumentType | undefined,
    search: search.trim() || undefined,
  })

  function setAssignment(value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set('assignment', value)
    else next.delete('assignment')
    setParams(next, { replace: true })
  }

  const hasFilters = Boolean(assignmentParam || status || type || search.trim())
  const clearFilters = () => {
    setAssignment('')
    setStatus('')
    setType('')
    setSearch('')
  }

  const columns = [
    {
      key: 'title',
      header: 'Document',
      render: (row: DocumentRow) => (
        <div className="flex min-w-0 items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-tint text-primary">
            <FileText className="h-[18px] w-[18px]" />
          </span>
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{row.title}</p>
            <p className="truncate text-xs text-ink-faint">{row.original_filename} · {formatFileSize(row.file_size)}</p>
          </div>
        </div>
      ),
    },
    { key: 'type', header: 'Type', render: (row: DocumentRow) => DOCUMENT_TYPE_LABEL[row.document_type] ?? row.document_type },
    { key: 'student', header: 'Student', render: (row: DocumentRow) => <PersonCell student={row.student} /> },
    { key: 'status', header: 'Status', render: (row: DocumentRow) => <MetaBadge map={DOCUMENT_STATUS} value={row.status} /> },
    { key: 'submitted', header: 'Submitted', render: (row: DocumentRow) => <span className="text-sm text-ink-soft">{formatDateTime(row.submitted_at)}</span> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Documents" description="Offer letters, reports and certificates your students upload. Open one to download and review it." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by student name or roll number" className="w-full sm:w-80" />
        <FilterSelect
          label="Student"
          value={assignmentParam}
          onChange={setAssignment}
          options={(assignments.data ?? []).map((a) => ({ value: a.id, label: `${a.student?.name ?? 'Student'} — ${a.internship?.company?.name ?? ''}` }))}
          allLabel="All students"
        />
        <FilterSelect label="Document status" value={status} onChange={setStatus} options={optionsFor(DOCUMENT_STATUS)} allLabel="All statuses" />
        <FilterSelect
          label="Document type"
          value={type}
          onChange={setType}
          options={Object.entries(DOCUMENT_TYPE_LABEL).map(([value, label]) => ({ value, label }))}
          allLabel="All types"
        />
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
              title="No documents match these filters"
              description="Try another student, type or status, or clear the filters."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No documents uploaded yet"
              description="When a student you coordinate uploads an offer letter, report or certificate, it will appear here for review."
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
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <p className="min-w-0 truncate text-sm font-medium text-ink">{row.title}</p>
                <MetaBadge map={DOCUMENT_STATUS} value={row.status} />
              </div>
              <PersonCell student={row.student} subtitle={`${DOCUMENT_TYPE_LABEL[row.document_type] ?? row.document_type} · ${formatFileSize(row.file_size)}`} />
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <DocumentDrawer documentId={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
    </div>
  )
}
