import { useState, type FormEvent } from 'react'
import { Link2, Pencil, Plus, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import { toast } from '@/components/ui/Toast'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { DetailItem, InternshipCell, MetaBadge } from '@/components/common/Cells'
import { DriveFormModal } from './DriveFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/dateFormat'
import { DRIVE_STATUS, DRIVE_TYPE_LABEL, metaFor } from '@/utils/statusMeta'
import type { DriveDetail } from '@/types/admin'

type Dialog = 'edit' | 'attach' | 'publish' | 'close' | 'cancel' | null

/** Drive detail: counts, attached opportunities (attach/detach while the drive is open), and the lifecycle DRAFT -> PUBLISHED -> CLOSED, with CANCELLED from DRAFT or PUBLISHED. */
export function DriveDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => adminService.drives.show(id as number), id)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [detaching, setDetaching] = useState<number | null>(null)

  const changed = () => {
    reload()
    onChanged()
  }

  async function run(fn: () => Promise<unknown>, success: string) {
    setBusy(true)
    setActionError(null)
    try {
      await fn()
      toast('success', success)
      setDialog(null)
      setDetaching(null)
      changed()
    } catch (err) {
      setDialog(null)
      setDetaching(null)
      setActionError(parseApiError(err, 'Unable to complete that action.').message)
    } finally {
      setBusy(false)
    }
  }

  const status = data?.status
  const editable = status === 'DRAFT' || status === 'PUBLISHED'
  const history: TimelineItem[] = (data?.status_history ?? []).map((entry, index, all) => ({
    id: entry.id,
    title: `${entry.from_status ? `${metaFor(DRIVE_STATUS, entry.from_status).label} → ` : ''}${metaFor(DRIVE_STATUS, entry.to_status).label}`,
    description: entry.remarks ?? undefined,
    timestamp: `${formatDateTime(entry.created_at)}${entry.changed_by ? ` · ${entry.changed_by.name}` : ''}`,
    status: index === all.length - 1 ? 'current' : 'completed',
  }))

  return (
    <>
      <Drawer open={id !== null} onClose={onClose} title="Placement drive" width="lg">
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-12 w-2/3" />
            <LoadingSkeleton className="h-4 w-full" />
            <LoadingSkeleton className="h-24 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : data ? (
          <div className="space-y-6">
            <div className="flex items-start gap-3">
              <CompanyLogo name={data.company?.name} logoPath={data.company?.logo_path} />
              <div className="min-w-0 flex-1">
                <h3 className="font-display text-lg font-semibold text-ink">{data.title}</h3>
                <p className="text-sm text-ink-soft">{data.company?.name} · {DRIVE_TYPE_LABEL[data.drive_type] ?? data.drive_type}</p>
              </div>
              <MetaBadge map={DRIVE_STATUS} value={data.status} />
            </div>

            {actionError && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{actionError}</div>}

            <div className="flex flex-wrap gap-2">
              {status === 'DRAFT' && <Button onClick={() => setDialog('publish')}>Publish</Button>}
              {status === 'PUBLISHED' && <Button onClick={() => setDialog('close')}>Close drive</Button>}
              {editable && <Button variant="secondary" onClick={() => setDialog('edit')}><Pencil className="h-4 w-4" />Edit</Button>}
              {editable && <Button variant="danger" onClick={() => setDialog('cancel')}>Cancel drive</Button>}
              {!editable && <p className="text-sm text-ink-soft">This drive is {status?.toLowerCase()} and can no longer be changed.</p>}
            </div>

            <div className="grid grid-cols-3 gap-3 text-center">
              {[
                ['Opportunities', data.opportunities.length],
                ['Applications', data.application_count],
                ['Selected', data.selected_count],
              ].map(([label, value]) => (
                <div key={label as string} className="rounded-xl border border-hairline p-3">
                  <p className="font-display text-2xl font-semibold tabular-nums text-ink">{value}</p>
                  <p className="text-xs text-ink-faint">{label}</p>
                </div>
              ))}
            </div>

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Drive date">{formatDateTime(data.scheduled_at)}</DetailItem>
              <DetailItem label="Application deadline">{formatDateTime(data.application_deadline)}</DetailItem>
              <DetailItem label="Venue">{data.venue}</DetailItem>
              <DetailItem label="Interviews">{String(data.interview_count)}</DetailItem>
              {data.cancellation_reason && <div className="col-span-2"><DetailItem label="Cancellation reason">{data.cancellation_reason}</DetailItem></div>}
            </dl>
            {data.description && <p className="whitespace-pre-line text-sm leading-relaxed text-ink">{data.description}</p>}

            <section>
              <div className="mb-3 flex items-center justify-between gap-3">
                <h4 className="text-sm font-semibold text-ink">Attached openings</h4>
                {editable && <Button variant="secondary" onClick={() => setDialog('attach')}><Plus className="h-4 w-4" />Attach</Button>}
              </div>
              {data.opportunities.length === 0 ? (
                <p className="rounded-xl border border-dashed border-hairline p-4 text-sm text-ink-soft">
                  No jobs or internships attached yet. A drive needs at least one before it can be published.
                </p>
              ) : (
                <ul className="space-y-2">
                  {data.opportunities.map((entry) => (
                    <li key={entry.attachment_id} className="flex items-center gap-3 rounded-xl border border-hairline p-3">
                      <div className="min-w-0 flex-1"><InternshipCell internship={entry.opportunity} /></div>
                      <span className="text-xs text-ink-faint">{entry.opportunity.type === 'internship' ? 'Internship' : 'Job'}</span>
                      {editable && (
                        <button type="button" aria-label={`Detach ${entry.opportunity.title}`} onClick={() => setDetaching(entry.attachment_id)} className="rounded-lg p-1.5 text-ink-soft hover:bg-sidebar hover:text-ink">
                          <X className="h-4 w-4" />
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">History</h4>
              <Timeline items={history} />
            </section>
          </div>
        ) : null}
      </Drawer>

      {dialog === 'edit' && data && <DriveFormModal existing={data} onClose={() => setDialog(null)} onSaved={changed} />}
      {dialog === 'attach' && data && <AttachModal drive={data} onClose={() => setDialog(null)} onDone={changed} />}
      {dialog === 'cancel' && data && <CancelModal driveId={data.id} onClose={() => setDialog(null)} onDone={changed} />}
      <ConfirmDialog
        open={dialog === 'publish'}
        onClose={() => setDialog(null)}
        onConfirm={() => data && run(() => adminService.drives.publish(data.id), 'Drive published.')}
        isConfirming={busy}
        title="Publish this drive?"
        description="Students will see the drive and its attached openings."
        confirmLabel="Publish"
      />
      <ConfirmDialog
        open={dialog === 'close'}
        onClose={() => setDialog(null)}
        onConfirm={() => data && run(() => adminService.drives.close(data.id), 'Drive closed.')}
        isConfirming={busy}
        title="Close this drive?"
        description="Closing is final - openings can no longer be attached or detached."
        confirmLabel="Close drive"
      />
      <ConfirmDialog
        open={detaching !== null}
        onClose={() => setDetaching(null)}
        onConfirm={() => data && detaching !== null && run(() => adminService.drives.detach(data.id, detaching), 'Opening detached.')}
        isConfirming={busy}
        title="Detach this opening?"
        description="Only the link to this drive is removed - the job or internship itself is untouched."
        confirmLabel="Detach"
        tone="danger"
      />
    </>
  )
}

function CancelModal({ driveId, onClose, onDone }: { driveId: number; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | undefined>()

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (!reason.trim()) {
      setFieldError('Give a reason for cancelling.')
      return
    }
    setFieldError(undefined)
    setBusy(true)
    try {
      await adminService.drives.cancel(driveId, reason.trim())
      toast('success', 'Drive cancelled.')
      onDone()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to cancel the drive.')
      setFieldError(parsed.fieldErrors.reason)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Cancel this drive?"
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Back</Button>
          <Button type="submit" form="drive-cancel" variant="danger" isLoading={busy}>Cancel drive</Button>
        </>
      }
    >
      <form id="drive-cancel" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <p className="text-sm text-ink-soft">A cancelled drive is final.</p>
        <div>
          <label className="label" htmlFor="dc-reason">Reason</label>
          <Textarea id="dc-reason" rows={3} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldError} />
        </div>
      </form>
    </Modal>
  )
}

/** Only this drive's company's jobs/internships can be attached (the API refuses others), so that is all the picker offers, minus what's already attached. */
function AttachModal({ drive, onClose, onDone }: { drive: DriveDetail; onClose: () => void; onDone: () => void }) {
  const companyId = drive.company?.id
  const options = useApiResource(async () => {
    const [jobs, internships] = await Promise.all([
      adminService.jobs.list({ company_id: companyId, per_page: 100 }),
      adminService.internships.list({ company_id: companyId, per_page: 100 }),
    ])
    const taken = new Set(drive.opportunities.map((o) => `${o.opportunity.type}:${o.opportunity.id}`))
    return [...jobs.items, ...internships.items].filter((o) => !taken.has(`${o.type}:${o.id}`) && o.status !== 'archived')
  }, `attach:${drive.id}`)
  const [choice, setChoice] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!choice) {
      setError('Choose a job or internship to attach.')
      return
    }
    const [type, id] = choice.split(':')
    setBusy(true)
    setError(null)
    try {
      await adminService.drives.attach(drive.id, type as 'job' | 'internship', Number(id))
      toast('success', 'Opening attached.')
      onDone()
      onClose()
    } catch (err) {
      setError(parseApiError(err, 'Unable to attach that opening.').message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Attach an opening"
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="drive-attach" isLoading={busy}><Link2 className="h-4 w-4" />Attach</Button>
        </>
      }
    >
      <form id="drive-attach" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <div>
          <label className="label" htmlFor="da-choice">Job or internship at {drive.company?.name}</label>
          <Select id="da-choice" value={choice} onChange={(e) => setChoice(e.target.value)}>
            <option value="">{options.isLoading ? 'Loading…' : 'Choose an opening…'}</option>
            {(options.data ?? []).map((o) => (
              <option key={`${o.type}:${o.id}`} value={`${o.type}:${o.id}`}>{o.title} ({o.type === 'job' ? 'Job' : 'Internship'}, {o.status})</option>
            ))}
          </Select>
          {options.error && <p className="mt-1 text-xs text-error">{options.error}</p>}
          {!options.isLoading && !options.error && (options.data ?? []).length === 0 && (
            <p className="mt-1 text-xs text-ink-faint">{drive.company?.name} has no other jobs or internships to attach. Create one under Jobs or Internships first.</p>
          )}
        </div>
      </form>
    </Modal>
  )
}
