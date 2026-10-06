import { useState, type FormEvent } from 'react'
import { CalendarClock, CheckCircle2, Pencil, XCircle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { Textarea } from '@/components/ui/Textarea'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import { toast } from '@/components/ui/Toast'
import { ApplicationBadge, DetailItem, InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { InterviewFormModal, type InterviewWriteService } from './InterviewFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/dateFormat'
import { INTERVIEW_MODE_LABEL, INTERVIEW_STATUS, metaFor } from '@/utils/statusMeta'
import type { InterviewDetail } from '@/types/admin'

type Dialog = 'edit' | 'reschedule' | 'complete' | 'cancel' | null

/** Reads and final transitions this drawer uses. Defaults to the placement office's endpoints; the company portal passes its own. */
export interface InterviewDrawerService {
  show: (id: number) => Promise<InterviewDetail>
  complete: (id: number, remarks?: string) => Promise<unknown>
  cancel: (id: number, reason: string) => Promise<unknown>
  writes?: InterviewWriteService
}

const ADMIN_INTERVIEWS: InterviewDrawerService = {
  show: adminService.interviews.show,
  complete: adminService.interviews.complete,
  cancel: adminService.interviews.cancel,
}

/** Interview detail with the lifecycle InterviewStatus allows: SCHEDULED/RESCHEDULED -> COMPLETED | CANCELLED | RESCHEDULED; completed/cancelled are final. */
export function InterviewDrawer({ id, onClose, onChanged, service = ADMIN_INTERVIEWS }: { id: number | null; onClose: () => void; onChanged: () => void; service?: InterviewDrawerService }) {
  const { data, isLoading, error, reload } = useApiResource(() => service.show(id as number), id)
  const canEdit = !service.writes || Boolean(service.writes.update)
  const [dialog, setDialog] = useState<Dialog>(null)
  const changed = () => {
    reload()
    onChanged()
  }

  const open = data && (data.status === 'SCHEDULED' || data.status === 'RESCHEDULED')
  const history: TimelineItem[] = (data?.status_history ?? []).map((entry, index, all) => ({
    id: entry.id,
    title: `${entry.from_status ? `${metaFor(INTERVIEW_STATUS, entry.from_status).label} → ` : ''}${metaFor(INTERVIEW_STATUS, entry.to_status).label}`,
    description: entry.remarks ?? undefined,
    timestamp: `${formatDateTime(entry.created_at)}${entry.changed_by ? ` · ${entry.changed_by.name}` : ''}`,
    status: index === all.length - 1 ? 'current' : 'completed',
  }))

  return (
    <>
      <Drawer open={id !== null} onClose={onClose} title={data ? `Round ${data.round}${data.title ? ` · ${data.title}` : ''}` : 'Interview'} width="lg">
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
              <PersonCell student={data.student} subtitle={data.student ? `${data.student.roll_number} · ${data.student.department ?? ''}` : undefined} />
              <MetaBadge map={INTERVIEW_STATUS} value={data.status} />
            </div>
            <div className="rounded-xl border border-hairline p-3">
              <InternshipCell internship={data.opportunity} />
              {data.application && <p className="mt-2 flex items-center gap-2 text-xs text-ink-faint">Application <ApplicationBadge status={data.application.status} /></p>}
            </div>

            {open ? (
              <div className="flex flex-wrap gap-2">
                <Button onClick={() => setDialog('complete')}><CheckCircle2 className="h-4 w-4" />Mark completed</Button>
                <Button variant="secondary" onClick={() => setDialog('reschedule')}><CalendarClock className="h-4 w-4" />Reschedule</Button>
                {canEdit && <Button variant="secondary" onClick={() => setDialog('edit')}><Pencil className="h-4 w-4" />Edit details</Button>}
                <Button variant="danger" onClick={() => setDialog('cancel')}><XCircle className="h-4 w-4" />Cancel</Button>
              </div>
            ) : (
              <p className="text-sm text-ink-soft">This interview is {data.status.toLowerCase()} and can no longer be changed.</p>
            )}

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="When">{formatDateTime(data.scheduled_at)}</DetailItem>
              <DetailItem label="Duration">{data.duration_minutes ? `${data.duration_minutes} minutes` : null}</DetailItem>
              <DetailItem label="Mode">{INTERVIEW_MODE_LABEL[data.mode] ?? data.mode}</DetailItem>
              <DetailItem label="Interviewer">{data.interviewer_name ? `${data.interviewer_name}${data.interviewer_email ? ` · ${data.interviewer_email}` : ''}` : data.interviewer_email}</DetailItem>
              <DetailItem label="Meeting link">{data.meeting_url ? <a href={data.meeting_url} target="_blank" rel="noreferrer" className="text-primary hover:underline">{data.meeting_url}</a> : null}</DetailItem>
              <DetailItem label="Venue">{data.venue}</DetailItem>
              {data.cancellation_reason && <div className="col-span-2"><DetailItem label="Cancellation reason">{data.cancellation_reason}</DetailItem></div>}
            </dl>
            {data.panel_notes && (
              <section>
                <h4 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Panel notes</h4>
                <p className="whitespace-pre-line text-sm text-ink">{data.panel_notes}</p>
              </section>
            )}
            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">History</h4>
              <Timeline items={history} />
            </section>
          </div>
        ) : null}
      </Drawer>

      {dialog === 'edit' && data && <InterviewFormModal variant={{ kind: 'edit', interview: data }} service={service.writes} onClose={() => setDialog(null)} onSaved={changed} />}
      {dialog === 'reschedule' && data && <InterviewFormModal variant={{ kind: 'reschedule', interview: data }} service={service.writes} onClose={() => setDialog(null)} onSaved={changed} />}
      {(dialog === 'complete' || dialog === 'cancel') && data && <ReasonModal kind={dialog} interviewId={data.id} service={service} onClose={() => setDialog(null)} onDone={changed} />}
    </>
  )
}

function ReasonModal({ kind, interviewId, service, onClose, onDone }: { kind: 'complete' | 'cancel'; interviewId: number; service: InterviewDrawerService; onClose: () => void; onDone: () => void }) {
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | undefined>()
  const isCancel = kind === 'cancel'

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (isCancel && !text.trim()) {
      setFieldError('Give a reason - the student is told why.')
      return
    }
    setFieldError(undefined)
    setBusy(true)
    try {
      if (isCancel) await service.cancel(interviewId, text.trim())
      else await service.complete(interviewId, text.trim() || undefined)
      toast('success', isCancel ? 'Interview cancelled.' : 'Interview marked completed.')
      onDone()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to update the interview.')
      setFieldError(parsed.fieldErrors.reason ?? parsed.fieldErrors.remarks)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={isCancel ? 'Cancel this interview?' : 'Mark interview completed?'}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Back</Button>
          <Button type="submit" form="iv-reason" variant={isCancel ? 'danger' : 'primary'} isLoading={busy}>{isCancel ? 'Cancel interview' : 'Mark completed'}</Button>
        </>
      }
    >
      <form id="iv-reason" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <p className="text-sm text-ink-soft">{isCancel ? 'A cancelled interview is final.' : 'A completed interview is final. Move the application forward from the application itself.'}</p>
        <div>
          <label className="label" htmlFor="ivr-text">{isCancel ? 'Reason' : 'Remarks (optional)'}</label>
          <Textarea id="ivr-text" rows={3} maxLength={1000} value={text} onChange={(e) => setText(e.target.value)} error={fieldError} />
        </div>
      </form>
    </Modal>
  )
}
