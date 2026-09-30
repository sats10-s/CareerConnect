import { useState, type FormEvent } from 'react'
import { Handshake, Plus } from 'lucide-react'
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
import { facultyService } from '@/services/facultyService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { formatDate, todayIso } from '@/utils/dateFormat'
import { formatInr } from '@/utils/numberFormat'
import { PPO_STATUS, optionsFor } from '@/utils/statusMeta'
import type { PpoFull, PpoRow } from '@/types/coordination'
import type { PpoStatus } from '@/types/internshipCompletion'

/** Pre-placement offers: one per internship; editable while OFFERED, then decided (accepted/declined) which is terminal. See InternshipCompletionService's PPO methods. */
export function PpoSection() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)

  const list = useApiList(facultyService.ppoRecords, { search: search.trim() || undefined, status: (status || undefined) as PpoStatus | undefined })
  const assignments = useFacultyAssignments()
  const existing = useApiResource(() => facultyService.ppoRecords({ per_page: 100 }).then((d) => d.items), 'ppo-all')
  const taken = new Set((existing.data ?? []).map((p) => p.internship_assignment_id))
  const eligible = (assignments.data ?? []).filter((a) => (a.status === 'ACTIVE' || a.status === 'COMPLETED') && !taken.has(a.id))

  const hasFilters = Boolean(search.trim() || status)
  const clearFilters = () => {
    setSearch('')
    setStatus('')
  }
  const refresh = () => {
    list.reload()
    existing.reload()
  }

  const columns = [
    { key: 'student', header: 'Student', render: (row: PpoRow) => <PersonCell student={row.student} /> },
    { key: 'company', header: 'Company', render: (row: PpoRow) => <InternshipCell internship={row.internship} /> },
    { key: 'designation', header: 'Designation', render: (row: PpoRow) => row.designation ?? '—' },
    { key: 'offered', header: 'Offered on', render: (row: PpoRow) => formatDate(row.offered_at) },
    { key: 'status', header: 'Status', render: (row: PpoRow) => <MetaBadge map={PPO_STATUS} value={row.status} /> },
  ]

  return (
    <div>
      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by student name or roll number" className="w-full sm:w-80" />
        <FilterSelect label="Offer status" value={status} onChange={setStatus} options={optionsFor(PPO_STATUS)} allLabel="All offers" />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
        <div className="w-full sm:ml-auto sm:w-auto">
          <Button onClick={() => setCreating(true)} className="w-full sm:w-auto">
            <Plus className="h-4 w-4" />
            Record PPO offer
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
              title="No offers match these filters"
              description="Try another status, or clear the filters."
              action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
            />
          ) : (
            <EmptyState
              illustration={<EmptyBriefcaseIllustration />}
              title="No pre-placement offers recorded"
              description="If a company offers one of your students a full-time role after their internship, record it here and track whether they accept."
              action={<Button onClick={() => setCreating(true)}><Handshake className="h-4 w-4" />Record PPO offer</Button>}
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
                <PersonCell student={row.student} subtitle={row.designation ?? undefined} />
                <MetaBadge map={PPO_STATUS} value={row.status} />
              </div>
              <InternshipCell internship={row.internship} />
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      {creating && <CreatePpoModal eligible={eligible} onClose={() => setCreating(false)} onSaved={refresh} />}
      <PpoDrawer ppoId={openId} onClose={() => setOpenId(null)} onChanged={refresh} />
    </div>
  )
}

interface PpoFormFieldsProps {
  offeredAt: string
  setOfferedAt: (v: string) => void
  designation: string
  setDesignation: (v: string) => void
  pkg: string
  setPkg: (v: string) => void
  joining: string
  setJoining: (v: string) => void
  remarks: string
  setRemarks: (v: string) => void
  errors: Record<string, string>
  idPrefix: string
}

function PpoFormFields(p: PpoFormFieldsProps) {
  return (
    <>
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="label" htmlFor={`${p.idPrefix}-offered`}>Offered on</label>
          <Input id={`${p.idPrefix}-offered`} type="date" max={todayIso()} value={p.offeredAt} onChange={(e) => p.setOfferedAt(e.target.value)} error={p.errors.offered_at} />
        </div>
        <div>
          <label className="label" htmlFor={`${p.idPrefix}-joining`}>Joining date (optional)</label>
          <Input id={`${p.idPrefix}-joining`} type="date" min={p.offeredAt} value={p.joining} onChange={(e) => p.setJoining(e.target.value)} error={p.errors.joining_date} />
        </div>
      </div>
      <div>
        <label className="label" htmlFor={`${p.idPrefix}-designation`}>Designation</label>
        <Input id={`${p.idPrefix}-designation`} maxLength={255} value={p.designation} onChange={(e) => p.setDesignation(e.target.value)} error={p.errors.designation} />
      </div>
      <div>
        <label className="label" htmlFor={`${p.idPrefix}-package`}>Annual package in ₹ (optional)</label>
        <Input id={`${p.idPrefix}-package`} type="number" min={0} step="1000" inputMode="numeric" value={p.pkg} onChange={(e) => p.setPkg(e.target.value)} error={p.errors.package} />
      </div>
      <div>
        <label className="label" htmlFor={`${p.idPrefix}-remarks`}>Remarks (optional)</label>
        <Textarea id={`${p.idPrefix}-remarks`} rows={3} maxLength={2000} value={p.remarks} onChange={(e) => p.setRemarks(e.target.value)} error={p.errors.remarks} />
      </div>
    </>
  )
}

function CreatePpoModal({ eligible, onClose, onSaved }: { eligible: { id: number; student: { name: string } | null; internship: { title: string; company: { name: string } | null } | null }[]; onClose: () => void; onSaved: () => void }) {
  const [assignmentId, setAssignmentId] = useState('')
  const [offeredAt, setOfferedAt] = useState(todayIso())
  const [designation, setDesignation] = useState('')
  const [pkg, setPkg] = useState('')
  const [joining, setJoining] = useState('')
  const [remarks, setRemarks] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function submit(event: FormEvent) {
    event.preventDefault()
    setFormError(null)
    const local: Record<string, string> = {}
    if (!assignmentId) local.assignment = 'Choose which internship this offer is for.'
    if (!designation.trim()) local.designation = 'Enter the designation being offered.'
    if (Object.keys(local).length) {
      setErrors(local)
      return
    }
    setErrors({})
    setSubmitting(true)
    try {
      await facultyService.createPpo(Number(assignmentId), {
        offered_at: offeredAt,
        designation: designation.trim(),
        joining_date: joining || null,
        package: pkg ? Number(pkg) : null,
        remarks: remarks.trim() || null,
      })
      toast('success', 'PPO offer recorded.')
      onSaved()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to record this offer.')
      setErrors(parsed.fieldErrors)
      setFormError(formBanner(parsed))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Record PPO offer"
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="ppo-create" isLoading={submitting}>Record offer</Button>
        </>
      }
    >
      <form id="ppo-create" onSubmit={submit} className="space-y-4" noValidate>
        {formError && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{formError}</div>}
        <div>
          <label className="label" htmlFor="ppo-assignment">Internship</label>
          <Select id="ppo-assignment" value={assignmentId} onChange={(e) => setAssignmentId(e.target.value)} error={errors.assignment}>
            <option value="">Choose a student…</option>
            {eligible.map((a) => (
              <option key={a.id} value={a.id}>{a.student?.name} — {a.internship?.company?.name ?? a.internship?.title}</option>
            ))}
          </Select>
          {eligible.length === 0 && <p className="mt-1 text-xs text-ink-faint">Every active or finished internship you coordinate already has a PPO record.</p>}
        </div>
        <PpoFormFields idPrefix="ppc" offeredAt={offeredAt} setOfferedAt={setOfferedAt} designation={designation} setDesignation={setDesignation} pkg={pkg} setPkg={setPkg} joining={joining} setJoining={setJoining} remarks={remarks} setRemarks={setRemarks} errors={errors} />
      </form>
    </Modal>
  )
}

function PpoDrawer({ ppoId, onClose, onChanged }: { ppoId: number | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => facultyService.ppoRecord(ppoId as number), ppoId)
  return (
    <Drawer open={ppoId !== null} onClose={onClose} title="PPO offer" width="lg">
      {isLoading && !data ? (
        <div className="space-y-4" aria-busy="true">
          <LoadingSkeleton className="h-10 w-2/3" />
          <LoadingSkeleton className="h-4 w-full" />
          <LoadingSkeleton className="h-24 w-full" />
        </div>
      ) : error ? (
        <ErrorState description={error} onRetry={reload} />
      ) : data ? (
        <PpoDetail key={`${data.id}-${data.updated_at}`} data={data} onSaved={() => { reload(); onChanged() }} />
      ) : null}
    </Drawer>
  )
}

function PpoDetail({ data, onSaved }: { data: PpoFull; onSaved: () => void }) {
  const open = data.status === 'OFFERED'
  const [offeredAt, setOfferedAt] = useState(data.offered_at ?? todayIso())
  const [designation, setDesignation] = useState(data.designation ?? '')
  const [pkg, setPkg] = useState(data.package ? String(Math.round(Number(data.package))) : '')
  const [joining, setJoining] = useState(data.joining_date ?? '')
  const [remarks, setRemarks] = useState(data.remarks ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [deciding, setDeciding] = useState<'ACCEPTED' | 'DECLINED' | null>(null)
  const [decidingBusy, setDecidingBusy] = useState(false)

  async function save(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setErrors({})
    if (!designation.trim()) {
      setErrors({ designation: 'Enter the designation being offered.' })
      return
    }
    setSaving(true)
    try {
      await facultyService.updatePpo(data.internship_assignment_id, {
        offered_at: offeredAt,
        designation: designation.trim(),
        joining_date: joining || null,
        package: pkg ? Number(pkg) : null,
        remarks: remarks.trim() || null,
      })
      toast('success', 'Offer updated.')
      onSaved()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to update this offer.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setSaving(false)
    }
  }

  async function decide() {
    if (!deciding) return
    setDecidingBusy(true)
    try {
      await facultyService.updatePpo(data.internship_assignment_id, { status: deciding })
      toast('success', deciding === 'ACCEPTED' ? 'Marked as accepted.' : 'Marked as declined.')
      setDeciding(null)
      onSaved()
    } catch (err) {
      setDeciding(null)
      setError(parseApiError(err, 'Unable to record the decision.').message)
    } finally {
      setDecidingBusy(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-3">
        <PersonCell student={data.student} />
        <MetaBadge map={PPO_STATUS} value={data.status} />
      </div>
      <div className="rounded-xl border border-hairline p-3"><InternshipCell internship={data.internship} /></div>

      {open ? (
        <form onSubmit={save} className="space-y-4" noValidate>
          <p className="text-sm text-ink-soft">Update the offer while it is still open. Once you record the student's decision, it can't be changed.</p>
          {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
          <PpoFormFields idPrefix="ppd" offeredAt={offeredAt} setOfferedAt={setOfferedAt} designation={designation} setDesignation={setDesignation} pkg={pkg} setPkg={setPkg} joining={joining} setJoining={setJoining} remarks={remarks} setRemarks={setRemarks} errors={errors} />
          <div className="flex flex-wrap gap-2">
            <Button type="submit" isLoading={saving}>Save changes</Button>
          </div>
          <div className="border-t border-hairline pt-4">
            <h3 className="mb-2 text-sm font-semibold text-ink">Student's decision</h3>
            <div className="flex flex-wrap gap-2">
              <Button type="button" variant="secondary" onClick={() => setDeciding('ACCEPTED')}>Mark accepted</Button>
              <Button type="button" variant="secondary" onClick={() => setDeciding('DECLINED')}>Mark declined</Button>
            </div>
          </div>
        </form>
      ) : (
        <>
          <dl className="grid grid-cols-2 gap-4">
            <DetailItem label="Designation">{data.designation}</DetailItem>
            <DetailItem label="Annual package">{formatInr(data.package)}</DetailItem>
            <DetailItem label="Offered on">{formatDate(data.offered_at)}</DetailItem>
            <DetailItem label="Joining date">{formatDate(data.joining_date)}</DetailItem>
          </dl>
          <section>
            <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Remarks</h3>
            {data.remarks ? <p className="whitespace-pre-line text-sm text-ink">{data.remarks}</p> : <p className="text-sm text-ink-faint">No remarks</p>}
          </section>
          <p className="text-sm text-ink-soft">This offer has been {data.status === 'ACCEPTED' ? 'accepted' : 'declined'} and can no longer be changed.</p>
        </>
      )}

      <ConfirmDialog
        open={deciding !== null}
        onClose={() => setDeciding(null)}
        onConfirm={decide}
        isConfirming={decidingBusy}
        title={deciding === 'ACCEPTED' ? 'Record the offer as accepted?' : 'Record the offer as declined?'}
        description="The decision is final - the offer can't be edited or reopened afterwards."
        confirmLabel={deciding === 'ACCEPTED' ? 'Mark accepted' : 'Mark declined'}
        tone={deciding === 'DECLINED' ? 'danger' : 'default'}
      />
    </div>
  )
}
