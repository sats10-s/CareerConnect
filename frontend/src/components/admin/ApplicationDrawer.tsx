import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { CalendarPlus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import { toast } from '@/components/ui/Toast'
import { labelForApplicationStatus } from '@/components/ui/StatusBadge'
import { ApplicationBadge, DetailItem, InternshipCell, PersonCell } from '@/components/common/Cells'
import { InterviewFormModal } from './InterviewFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import { formatInr } from '@/utils/numberFormat'
import { APPLICATION_TRANSITIONS } from '@/utils/statusMeta'
import type { ApplicationStatus } from '@/types/application'

const ACTION_LABEL: Record<string, string> = {
  UNDER_REVIEW: 'Start review',
  SHORTLISTED: 'Shortlist',
  ASSESSMENT: 'Move to assessment',
  SELECTED: 'Select',
  REJECTED: 'Reject',
}

/** Scheduling is only offered from these statuses (InterviewService::INTERVIEWABLE_APPLICATION_STATUSES). */
const SCHEDULABLE = ['SHORTLISTED', 'ASSESSMENT', 'INTERVIEW']

interface ApplicationDrawerProps {
  id: number | null
  onClose: () => void
  onChanged: () => void
}

export function ApplicationDrawer({ id, onClose, onChanged }: ApplicationDrawerProps) {
  const { data, isLoading, error, reload } = useApiResource(() => adminService.applications.show(id as number), id)
  const [target, setTarget] = useState<ApplicationStatus | null>(null)
  const [scheduling, setScheduling] = useState(false)
  const [editingPackage, setEditingPackage] = useState(false)

  const history: TimelineItem[] = (data?.status_history ?? []).map((entry, index, all) => ({
    id: entry.id,
    title: `${entry.from_status ? `${labelForApplicationStatus(entry.from_status)} → ` : ''}${labelForApplicationStatus(entry.to_status)}`,
    description: entry.remarks ?? undefined,
    timestamp: `${formatDateTime(entry.created_at)}${entry.changed_by ? ` · ${entry.changed_by.name}` : ''}`,
    status: index === all.length - 1 ? 'current' : 'completed',
  }))

  // INTERVIEW is reachable through "Schedule interview" (which also records the details), so no bare "Move to interview" button.
  const moves = data ? (APPLICATION_TRANSITIONS[data.status] ?? []).filter((s) => s !== 'INTERVIEW') : []
  const s = data?.student

  return (
    <>
      <Drawer open={id !== null} onClose={onClose} title="Application" width="lg">
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-10 w-2/3" />
            <LoadingSkeleton className="h-4 w-full" />
            <LoadingSkeleton className="h-24 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : data ? (
          <div className="space-y-6">
            <div className="flex items-start justify-between gap-3">
              <PersonCell student={s} subtitle={s ? `${s.roll_number} · ${s.department ?? ''}` : undefined} />
              <ApplicationBadge status={data.status} />
            </div>
            <div className="rounded-xl border border-hairline p-3">
              <InternshipCell internship={data.opportunity} />
              <p className="mt-2 text-xs text-ink-faint">{data.opportunity?.type === 'internship' ? 'Internship' : 'Job'} · applied {formatDate(data.applied_at)}</p>
            </div>

            <div className="flex flex-wrap gap-2">
              {moves.map((status) => (
                <Button key={status} variant={status === 'REJECTED' ? 'danger' : status === 'SELECTED' ? 'primary' : 'secondary'} onClick={() => setTarget(status as ApplicationStatus)}>
                  {ACTION_LABEL[status] ?? labelForApplicationStatus(status)}
                </Button>
              ))}
              {SCHEDULABLE.includes(data.status) && (
                <Button variant="secondary" onClick={() => setScheduling(true)}>
                  <CalendarPlus className="h-4 w-4" />
                  Schedule interview
                </Button>
              )}
            </div>
            {moves.length === 0 && !SCHEDULABLE.includes(data.status) && (
              <p className="text-sm text-ink-soft">
                {data.status === 'WITHDRAWN' ? 'The student withdrew this application.' : 'This application has reached a final status.'}
                {data.status === 'SELECTED' && data.opportunity?.type === 'internship' && (
                  <> <Link to="/admin/internships?tab=assignments" className="font-medium text-primary hover:underline">Assign a faculty coordinator</Link>.</>
                )}
              </p>
            )}

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Email">{s?.email}</DetailItem>
              <DetailItem label="Batch">{s?.batch}</DetailItem>
              <DetailItem label="Year">{s?.year ? `Year ${s.year}` : null}</DetailItem>
              <DetailItem label="CGPA">{s?.cgpa != null ? String(s.cgpa) : null}</DetailItem>
              <DetailItem label="Backlogs">{s?.backlogs != null ? String(s.backlogs) : null}</DetailItem>
              <DetailItem label="Selected on">{formatDate(data.selected_at)}</DetailItem>
              {data.status === 'SELECTED' && data.opportunity?.type !== 'internship' && (
                <DetailItem label="Annual package">
                  {data.offer_package != null ? formatInr(data.offer_package) : 'Not entered'}{' '}
                  <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setEditingPackage(true)}>{data.offer_package != null ? 'Edit' : 'Add'}</button>
                </DetailItem>
              )}
            </dl>

            {data.rejection_reason && (
              <section className="rounded-xl bg-error-bg p-3">
                <h4 className="mb-1 text-xs font-medium uppercase tracking-wide text-error">Rejection reason</h4>
                <p className="whitespace-pre-line text-sm text-ink">{data.rejection_reason}</p>
              </section>
            )}
            {data.notes && (
              <section>
                <h4 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Notes</h4>
                <p className="whitespace-pre-line text-sm text-ink">{data.notes}</p>
              </section>
            )}

            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">Status history</h4>
              {history.length > 0 ? <Timeline items={history} /> : <p className="text-sm text-ink-faint">No status changes recorded yet.</p>}
            </section>
          </div>
        ) : null}
      </Drawer>

      {target && data && (
        <StatusChangeModal
          applicationId={data.id}
          who={`${data.student?.name ?? ''} · ${data.opportunity?.title ?? ''}`}
          target={target}
          isJob={data.opportunity?.type !== 'internship'}
          onClose={() => setTarget(null)}
          onDone={() => {
            reload()
            onChanged()
          }}
        />
      )}
      {editingPackage && data && (
        <PackageModal
          applicationId={data.id}
          current={data.offer_package ?? null}
          onClose={() => setEditingPackage(false)}
          onDone={() => {
            reload()
            onChanged()
          }}
        />
      )}
      {scheduling && data && (
        <InterviewFormModal
          variant={{ kind: 'schedule', applicationId: data.id, who: `${data.student?.name ?? ''} · ${data.opportunity?.title ?? ''}` }}
          onClose={() => setScheduling(false)}
          onSaved={() => {
            reload()
            onChanged()
          }}
        />
      )}
    </>
  )
}

/** Annual package in rupees, as typed (blank = not entered). Returns undefined when the text isn't a valid amount. */
function parsePackage(text: string): number | null | undefined {
  if (!text.trim()) return null
  const value = Number(text)
  return Number.isFinite(value) && value >= 0 ? value : undefined
}

function PackageModal({ applicationId, current, onClose, onDone }: { applicationId: number; current: number | null; onClose: () => void; onDone: () => void }) {
  const [pkg, setPkg] = useState(current != null ? String(Math.round(current)) : '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    const value = parsePackage(pkg)
    if (value === undefined) {
      setError('Enter the annual package in rupees, e.g. 650000.')
      return
    }
    setBusy(true)
    try {
      await adminService.applications.updateOffer(applicationId, value)
      toast('success', 'Package saved.')
      onDone()
      onClose()
    } catch (err) {
      setError(formBanner(parseApiError(err, 'Unable to save the package.')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Annual package"
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="package-form" isLoading={busy}>Save</Button>
        </>
      }
    >
      <form id="package-form" onSubmit={submit} noValidate className="space-y-2">
        <label className="label" htmlFor="pk-amount">Annual package in ₹ (leave blank if not known)</label>
        <Input id="pk-amount" type="number" min={0} step="1000" inputMode="numeric" value={pkg} onChange={(e) => setPkg(e.target.value)} error={error ?? undefined} />
        <p className="text-xs text-ink-faint">Used by the Package report in Excel Sync.</p>
      </form>
    </Modal>
  )
}

function StatusChangeModal({ applicationId, who, target, isJob, onClose, onDone }: { applicationId: number; who: string; target: ApplicationStatus; isJob: boolean; onClose: () => void; onDone: () => void }) {
  const [remarks, setRemarks] = useState('')
  const [pkg, setPkg] = useState('')
  const [pkgError, setPkgError] = useState<string | null>(null)
  const askPackage = target === 'SELECTED' && isJob
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const isReject = target === 'REJECTED'

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (isReject && !remarks.trim()) {
      setFieldError('Give a reason - it is saved on the application as the rejection reason.')
      return
    }
    const offerPackage = askPackage ? parsePackage(pkg) : null
    if (offerPackage === undefined) {
      setPkgError('Enter the annual package in rupees, e.g. 650000, or leave it blank.')
      return
    }
    setPkgError(null)
    setFieldError(null)
    setBusy(true)
    try {
      await adminService.applications.updateStatus(applicationId, target, remarks.trim() || undefined, offerPackage)
      toast('success', `Application moved to ${labelForApplicationStatus(target)}.`)
      onDone()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to change the status.')
      setFieldError(parsed.fieldErrors.remarks ?? null)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`${ACTION_LABEL[target] ?? labelForApplicationStatus(target)}?`}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Back</Button>
          <Button type="submit" form="status-form" variant={isReject ? 'danger' : 'primary'} isLoading={busy}>{ACTION_LABEL[target] ?? 'Confirm'}</Button>
        </>
      }
    >
      <form id="status-form" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <p className="text-sm text-ink-soft">{who}</p>
        {(target === 'SELECTED' || isReject) && <p className="text-sm text-ink-soft">{isReject ? 'Rejection is final.' : 'Selection is final.'} The student is notified.</p>}
        {askPackage && (
          <div>
            <label className="label" htmlFor="sc-package">Annual package in ₹ (optional)</label>
            <Input id="sc-package" type="number" min={0} step="1000" inputMode="numeric" value={pkg} onChange={(e) => setPkg(e.target.value)} error={pkgError ?? undefined} />
            <p className="mt-1 text-xs text-ink-faint">You can add or change it later from this application.</p>
          </div>
        )}
        <div>
          <label className="label" htmlFor="sc-remarks">{isReject ? 'Reason' : 'Remarks (optional)'}</label>
          <Textarea id="sc-remarks" rows={3} maxLength={1000} value={remarks} onChange={(e) => setRemarks(e.target.value)} error={fieldError ?? undefined} />
        </div>
      </form>
    </Modal>
  )
}
