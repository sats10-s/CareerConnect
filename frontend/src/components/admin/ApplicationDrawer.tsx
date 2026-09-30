import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { CalendarPlus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
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
          onClose={() => setTarget(null)}
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

function StatusChangeModal({ applicationId, who, target, onClose, onDone }: { applicationId: number; who: string; target: ApplicationStatus; onClose: () => void; onDone: () => void }) {
  const [remarks, setRemarks] = useState('')
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
    setFieldError(null)
    setBusy(true)
    try {
      await adminService.applications.updateStatus(applicationId, target, remarks.trim() || undefined)
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
        <div>
          <label className="label" htmlFor="sc-remarks">{isReject ? 'Reason' : 'Remarks (optional)'}</label>
          <Textarea id="sc-remarks" rows={3} maxLength={1000} value={remarks} onChange={(e) => setRemarks(e.target.value)} error={fieldError ?? undefined} />
        </div>
      </form>
    </Modal>
  )
}
