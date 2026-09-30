import { useState, type FormEvent } from 'react'
import { ClipboardCheck, Lock, Plus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataTable } from '@/components/ui/DataTable'
import { Drawer } from '@/components/ui/Drawer'
import { EmptyState } from '@/components/ui/EmptyState'
import { ErrorState } from '@/components/ui/ErrorState'
import { Input } from '@/components/ui/Input'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { EmptyBriefcaseIllustration, EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { DetailItem, InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { useApiList } from '@/hooks/useApiList'
import { useApiResource } from '@/hooks/useApiResource'
import { useFacultyAssignments } from '@/hooks/useFacultyAssignments'
import { facultyService, type CompletionPayload } from '@/services/facultyService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { formatDate, formatDateTime, todayIso } from '@/utils/dateFormat'
import { COMPLETION_OUTCOME, COMPLETION_STATUS, optionsFor } from '@/utils/statusMeta'
import type { CompletionFull, CompletionRow } from '@/types/coordination'
import type { InternshipCompletionOutcome, InternshipCompletionRecordStatus } from '@/types/internshipCompletion'

/** Completion assessment: one record per internship, DRAFT until finalized (terminal). See InternshipCompletionService. */
export function CompletionSection() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [outcome, setOutcome] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)

  const list = useApiList(facultyService.completions, {
    search: search.trim() || undefined,
    status: (status || undefined) as InternshipCompletionRecordStatus | undefined,
    outcome: (outcome || undefined) as InternshipCompletionOutcome | undefined,
  })
  const assignments = useFacultyAssignments()
  const existing = useApiResource(() => facultyService.completions({ per_page: 100 }).then((d) => d.items), 'completions-all')

  const taken = new Set((existing.data ?? []).map((c) => c.internship_assignment_id))
  const eligible = (assignments.data ?? []).filter((a) => (a.status === 'ACTIVE' || a.status === 'COMPLETED') && !taken.has(a.id))

  const hasFilters = Boolean(search.trim() || status || outcome)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
    setOutcome('')
  }
  const refresh = () => {
    list.reload()
    existing.reload()
  }

  const columns = [
    { key: 'student', header: 'Student', render: (row: CompletionRow) => <PersonCell student={row.student} /> },
    { key: 'internship', header: 'Internship', render: (row: CompletionRow) => <InternshipCell internship={row.internship} /> },
    { key: 'date', header: 'Completion date', render: (row: CompletionRow) => formatDate(row.completion_date) },
    { key: 'outcome', header: 'Outcome', render: (row: CompletionRow) => <MetaBadge map={COMPLETION_OUTCOME} value={row.outcome} /> },
    { key: 'status', header: 'Record', render: (row: CompletionRow) => <MetaBadge map={COMPLETION_STATUS} value={row.status} /> },
  ]

  return (
    <div>
      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by student name or roll number" className="w-full sm:w-80" />
        <FilterSelect label="Record status" value={status} onChange={setStatus} options={optionsFor(COMPLETION_STATUS)} allLabel="All records" />
        <FilterSelect label="Outcome" value={outcome} onChange={setOutcome} options={optionsFor(COMPLETION_OUTCOME)} allLabel="Any outcome" />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
        <div className="w-full sm:ml-auto sm:w-auto">
          <Button onClick={() => setCreating(true)} className="w-full sm:w-auto">
            <Plus className="h-4 w-4" />
            Record completion
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
            <EmptyState
              illustration={<EmptySearchIllustration />}
              title="No completion records match these filters"
              description="Try another status or outcome, or clear the filters."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No completion records yet"
              description="When a student's internship wraps up, record whether they completed it. Drafts can be edited until you finalize them."
              action={<Button onClick={() => setCreating(true)}><ClipboardCheck className="h-4 w-4" />Record completion</Button>}
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
                <PersonCell student={row.student} subtitle={formatDate(row.completion_date)} />
                <MetaBadge map={COMPLETION_STATUS} value={row.status} />
              </div>
              <div className="flex items-center justify-between gap-3">
                <InternshipCell internship={row.internship} />
                <MetaBadge map={COMPLETION_OUTCOME} value={row.outcome} />
              </div>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      {creating && <CreateCompletionModal eligible={eligible} onClose={() => setCreating(false)} onSaved={refresh} />}
      <CompletionDrawer completionId={openId} onClose={() => setOpenId(null)} onChanged={refresh} />
    </div>
  )
}

function CreateCompletionModal({ eligible, onClose, onSaved }: { eligible: { id: number; student: { name: string } | null; internship: { title: string; company: { name: string } | null } | null }[]; onClose: () => void; onSaved: () => void }) {
  const [assignmentId, setAssignmentId] = useState('')
  const [date, setDate] = useState(todayIso())
  const [outcome, setOutcome] = useState<InternshipCompletionOutcome>('COMPLETED')
  const [remarks, setRemarks] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  async function submit(event: FormEvent) {
    event.preventDefault()
    setFormError(null)
    setFieldErrors({})
    if (!assignmentId) {
      setFieldErrors({ assignment: 'Choose which internship this is for.' })
      return
    }
    setSubmitting(true)
    try {
      await facultyService.createCompletion(Number(assignmentId), { completion_date: date, outcome, final_remarks: remarks.trim() || null })
      toast('success', 'Completion record saved as a draft.')
      onSaved()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save the completion record.')
      setFieldErrors(parsed.fieldErrors)
      setFormError(formBanner(parsed))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Record completion"
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="completion-create" isLoading={submitting}>Save draft</Button>
        </>
      }
    >
      <form id="completion-create" onSubmit={submit} className="space-y-4" noValidate>
        {formError && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{formError}</div>}
        <div>
          <label className="label" htmlFor="cmp-assignment">Internship</label>
          <Select id="cmp-assignment" value={assignmentId} onChange={(e) => setAssignmentId(e.target.value)} error={fieldErrors.assignment}>
            <option value="">Choose a student…</option>
            {eligible.map((a) => (
              <option key={a.id} value={a.id}>{a.student?.name} — {a.internship?.company?.name ?? a.internship?.title}</option>
            ))}
          </Select>
          {eligible.length === 0 && <p className="mt-1 text-xs text-ink-faint">Every active or finished internship you coordinate already has a completion record.</p>}
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label" htmlFor="cmp-date">Completion date</label>
            <Input id="cmp-date" type="date" max={todayIso()} value={date} onChange={(e) => setDate(e.target.value)} error={fieldErrors.completion_date} />
          </div>
          <div>
            <label className="label" htmlFor="cmp-outcome">Outcome</label>
            <Select id="cmp-outcome" value={outcome} onChange={(e) => setOutcome(e.target.value as InternshipCompletionOutcome)} error={fieldErrors.outcome}>
              {optionsFor(COMPLETION_OUTCOME).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </Select>
          </div>
        </div>
        <div>
          <label className="label" htmlFor="cmp-remarks">Final remarks (optional)</label>
          <Textarea id="cmp-remarks" rows={3} maxLength={2000} value={remarks} onChange={(e) => setRemarks(e.target.value)} error={fieldErrors.final_remarks} />
        </div>
      </form>
    </Modal>
  )
}

function CompletionDrawer({ completionId, onClose, onChanged }: { completionId: number | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => facultyService.completion(completionId as number), completionId)
  return (
    <Drawer open={completionId !== null} onClose={onClose} title="Completion record" width="lg">
      {isLoading && !data ? (
        <div className="space-y-4" aria-busy="true">
          <LoadingSkeleton className="h-10 w-2/3" />
          <LoadingSkeleton className="h-4 w-full" />
          <LoadingSkeleton className="h-24 w-full" />
        </div>
      ) : error ? (
        <ErrorState description={error} onRetry={reload} />
      ) : data ? (
        <CompletionDetail key={`${data.id}-${data.updated_at}`} data={data} onSaved={() => { reload(); onChanged() }} />
      ) : null}
    </Drawer>
  )
}

function CompletionDetail({ data, onSaved }: { data: CompletionFull; onSaved: () => void }) {
  const isDraft = data.status === 'DRAFT'
  const [date, setDate] = useState(data.completion_date ?? todayIso())
  const [outcome, setOutcome] = useState<InternshipCompletionOutcome>(data.outcome ?? 'COMPLETED')
  const [remarks, setRemarks] = useState(data.final_remarks ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [confirming, setConfirming] = useState(false)
  const [finalizing, setFinalizing] = useState(false)

  async function save(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})
    setSaving(true)
    try {
      const payload: Partial<CompletionPayload> = { completion_date: date, outcome, final_remarks: remarks.trim() || null }
      await facultyService.updateCompletion(data.internship_assignment_id, payload)
      toast('success', 'Draft updated.')
      onSaved()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to update the draft.')
      setFieldErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setSaving(false)
    }
  }

  async function finalize() {
    setFinalizing(true)
    try {
      await facultyService.finalizeCompletion(data.id, remarks.trim() || undefined)
      toast('success', 'Completion finalized.')
      setConfirming(false)
      onSaved()
    } catch (err) {
      setConfirming(false)
      setError(parseApiError(err, 'Unable to finalize this record.').message)
    } finally {
      setFinalizing(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-3">
        <PersonCell student={data.student} />
        <MetaBadge map={COMPLETION_STATUS} value={data.status} />
      </div>
      <div className="rounded-xl border border-hairline p-3"><InternshipCell internship={data.internship} /></div>

      {isDraft ? (
        <form onSubmit={save} className="space-y-4" noValidate>
          <p className="text-sm text-ink-soft">This is a draft. Edit it freely, then finalize once you are sure - a finalized record can no longer be changed.</p>
          {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label" htmlFor="cd-date">Completion date</label>
              <Input id="cd-date" type="date" max={todayIso()} value={date} onChange={(e) => setDate(e.target.value)} error={fieldErrors.completion_date} />
            </div>
            <div>
              <label className="label" htmlFor="cd-outcome">Outcome</label>
              <Select id="cd-outcome" value={outcome} onChange={(e) => setOutcome(e.target.value as InternshipCompletionOutcome)} error={fieldErrors.outcome}>
                {optionsFor(COMPLETION_OUTCOME).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select>
            </div>
          </div>
          <div>
            <label className="label" htmlFor="cd-remarks">Final remarks</label>
            <Textarea id="cd-remarks" rows={4} maxLength={2000} value={remarks} onChange={(e) => setRemarks(e.target.value)} error={fieldErrors.final_remarks} />
          </div>
          <div className="flex flex-wrap gap-2">
            <Button type="submit" isLoading={saving}>Save draft</Button>
            <Button type="button" variant="secondary" onClick={() => setConfirming(true)}>
              <Lock className="h-4 w-4" />
              Finalize
            </Button>
          </div>
        </form>
      ) : (
        <>
          <dl className="grid grid-cols-2 gap-4">
            <DetailItem label="Completion date">{formatDate(data.completion_date)}</DetailItem>
            <DetailItem label="Outcome"><MetaBadge map={COMPLETION_OUTCOME} value={data.outcome} /></DetailItem>
            <DetailItem label="Finalized">{formatDateTime(data.finalized_at)}</DetailItem>
            <DetailItem label="Recorded by">{data.completed_by?.name}</DetailItem>
          </dl>
          <section>
            <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Final remarks</h3>
            {data.final_remarks ? <p className="whitespace-pre-line text-sm text-ink">{data.final_remarks}</p> : <p className="text-sm text-ink-faint">No remarks</p>}
          </section>
          <p className="flex items-center gap-2 text-sm text-ink-soft"><Lock className="h-4 w-4" /> Finalized records can't be changed.</p>
        </>
      )}

      {data.certificate_document && (
        <DetailItem label="Certificate">{data.certificate_document.title}</DetailItem>
      )}

      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        onConfirm={finalize}
        isConfirming={finalizing}
        title="Finalize this completion record?"
        description="Once finalized the outcome, date and remarks are locked. This cannot be undone."
        confirmLabel="Finalize"
      />
    </div>
  )
}
