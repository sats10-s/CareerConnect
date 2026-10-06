import { useState, type FormEvent } from 'react'
import { CalendarPlus, Download, ExternalLink, ShieldCheck } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { Textarea } from '@/components/ui/Textarea'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import { toast } from '@/components/ui/Toast'
import { labelForApplicationStatus } from '@/components/ui/StatusBadge'
import { ApplicationBadge, DetailItem } from '@/components/common/Cells'
import { InterviewFormModal } from '@/components/admin/InterviewFormModal'
import { COMPANY_INTERVIEW_WRITES } from './interviewServices'
import { useApiResource } from '@/hooks/useApiResource'
import { companyService } from '@/services/companyService'
import { formBanner, parseApiError, parseBlobApiError } from '@/utils/apiError'
import { saveBlob } from '@/utils/downloadBlob'
import { formatDate, formatDateTime, formatFileSize } from '@/utils/dateFormat'
import { APPLICATION_TRANSITIONS } from '@/utils/statusMeta'
import type { ApplicationStatus } from '@/types/application'

const ACTION_LABEL: Record<string, string> = {
  UNDER_REVIEW: 'Start review',
  SHORTLISTED: 'Shortlist',
  ASSESSMENT: 'Move to assessment',
  SELECTED: 'Select',
  REJECTED: 'Reject',
}

/** InterviewService::INTERVIEWABLE_APPLICATION_STATUSES */
const SCHEDULABLE = ['SHORTLISTED', 'ASSESSMENT', 'INTERVIEW']

function ProfileLink({ href, label }: { href: string | null; label: string }) {
  if (!href) return null
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
      {label}
      <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
    </a>
  )
}

/** One applicant to the recruiter's own posting - the privacy-safe profile, history and status moves. */
export function ApplicantDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => companyService.applicants.show(id as number), id)
  const [target, setTarget] = useState<ApplicationStatus | null>(null)
  const [scheduling, setScheduling] = useState(false)
  const [downloading, setDownloading] = useState(false)

  async function downloadResume() {
    if (!data?.student.resume) return
    setDownloading(true)
    try {
      saveBlob(await companyService.applicants.resume(data.id), data.student.resume.original_name || 'resume')
    } catch (err) {
      toast('error', (await parseBlobApiError(err, 'Unable to download the resume.')).message)
    } finally {
      setDownloading(false)
    }
  }

  const history: TimelineItem[] = (data?.status_history ?? []).map((entry, index, all) => ({
    id: entry.id,
    title: `${entry.from_status ? `${labelForApplicationStatus(entry.from_status)} → ` : ''}${labelForApplicationStatus(entry.to_status)}`,
    description: entry.remarks ?? undefined,
    timestamp: formatDateTime(entry.created_at),
    status: index === all.length - 1 ? 'current' : 'completed',
  }))
  const moves = data ? (APPLICATION_TRANSITIONS[data.status] ?? []).filter((s) => s !== 'INTERVIEW') : []
  const s = data?.student
  const changed = () => {
    reload()
    onChanged()
  }

  return (
    <>
      <Drawer
        open={id !== null}
        onClose={onClose}
        title={s?.name ?? 'Applicant'}
        width="lg"
        footer={data && (moves.length > 0 || SCHEDULABLE.includes(data.status)) ? (
          <div className="flex flex-wrap justify-end gap-3">
            {SCHEDULABLE.includes(data.status) && (
              <Button variant="secondary" onClick={() => setScheduling(true)}>
                <CalendarPlus className="h-4 w-4" />
                Schedule interview
              </Button>
            )}
            {moves.map((status) => (
              <Button key={status} variant={status === 'REJECTED' ? 'danger' : status === 'SELECTED' ? 'primary' : 'secondary'} onClick={() => setTarget(status as ApplicationStatus)}>
                {ACTION_LABEL[status] ?? labelForApplicationStatus(status)}
              </Button>
            ))}
          </div>
        ) : undefined}
      >
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-10 w-1/2" />
            <LoadingSkeleton className="h-32 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : data && s ? (
          <div className="space-y-6">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-sm text-ink-soft">{data.opportunity?.title ?? 'Posting'} · applied {formatDate(data.applied_at)}</p>
              </div>
              <ApplicationBadge status={data.status} />
            </div>

            <p className="flex items-start gap-2 rounded-lg border border-hairline bg-surface-raised px-3.5 py-2.5 text-xs text-ink-soft">
              <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
              Students’ phone numbers and email addresses aren’t shared with recruiters. The placement office coordinates contact.
            </p>

            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <DetailItem label="Roll number">{s.roll_number}</DetailItem>
              <DetailItem label="Department">{s.department}</DetailItem>
              <DetailItem label="Batch">{s.batch}</DetailItem>
              <DetailItem label="Year">{s.year ? `Year ${s.year}` : null}</DetailItem>
              <DetailItem label="CGPA">{s.cgpa != null ? s.cgpa.toFixed(2) : null}</DetailItem>
              <DetailItem label="Backlogs">{s.backlogs != null ? String(s.backlogs) : null}</DetailItem>
            </dl>

            {(s.linkedin_url || s.github_url || s.portfolio_url) && (
              <div className="flex flex-wrap gap-4">
                <ProfileLink href={s.linkedin_url} label="LinkedIn" />
                <ProfileLink href={s.github_url} label="GitHub" />
                <ProfileLink href={s.portfolio_url} label="Portfolio" />
              </div>
            )}

            <section>
              <h4 className="mb-2 text-sm font-semibold text-ink">Resume</h4>
              {s.resume ? (
                <div className="flex items-center justify-between gap-3 rounded-xl border border-hairline p-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm text-ink">{s.resume.original_name ?? 'Resume'}</p>
                    <p className="text-xs text-ink-faint">{s.resume.size ? formatFileSize(s.resume.size) : 'Size unknown'} · uploaded {formatDate(s.resume.uploaded_at)}</p>
                  </div>
                  <Button variant="secondary" onClick={downloadResume} isLoading={downloading}>
                    <Download className="h-4 w-4" />
                    Download
                  </Button>
                </div>
              ) : <p className="text-sm text-ink-faint">No resume uploaded.</p>}
            </section>

            <section>
              <h4 className="mb-2 text-sm font-semibold text-ink">Skills</h4>
              {s.skills && s.skills.length > 0 ? (
                <ul className="flex flex-wrap gap-2">
                  {s.skills.map((skill) => <li key={skill} className="rounded-full bg-sidebar px-2.5 py-0.5 text-xs font-medium text-ink-soft">{skill}</li>)}
                </ul>
              ) : <p className="text-sm text-ink-faint">No skills listed.</p>}
            </section>

            {s.projects && s.projects.length > 0 && (
              <section>
                <h4 className="mb-2 text-sm font-semibold text-ink">Projects</h4>
                <ul className="space-y-3">
                  {s.projects.map((p, i) => (
                    <li key={i} className="card p-3">
                      <p className="text-sm font-medium text-ink">{p.title}</p>
                      {p.technologies && <p className="text-xs text-ink-faint">{p.technologies}</p>}
                      {p.description && <p className="mt-1 text-sm text-ink-soft">{p.description}</p>}
                    </li>
                  ))}
                </ul>
              </section>
            )}

            {s.certifications && s.certifications.length > 0 && (
              <section>
                <h4 className="mb-2 text-sm font-semibold text-ink">Certifications</h4>
                <ul className="space-y-1 text-sm text-ink-soft">
                  {s.certifications.map((c, i) => <li key={i}><span className="text-ink">{c.name}</span>{c.issuing_organization ? ` · ${c.issuing_organization}` : ''}</li>)}
                </ul>
              </section>
            )}

            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">History</h4>
              {history.length > 0 ? <Timeline items={history} /> : <p className="text-sm text-ink-faint">No status changes yet.</p>}
            </section>
          </div>
        ) : null}
      </Drawer>

      {target && data && <StatusChangeModal applicationId={data.id} who={s?.name ?? 'This applicant'} target={target} onClose={() => setTarget(null)} onDone={changed} />}
      {scheduling && data && (
        <InterviewFormModal
          variant={{ kind: 'schedule', applicationId: data.id, who: s?.name ?? 'Applicant' }}
          service={COMPANY_INTERVIEW_WRITES}
          onClose={() => setScheduling(false)}
          onSaved={changed}
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
      setFieldError('Give a reason. It’s saved on the application.')
      return
    }
    setFieldError(null)
    setBusy(true)
    try {
      await companyService.applicants.updateStatus(applicationId, target, remarks.trim() || undefined)
      toast('success', `Moved to ${labelForApplicationStatus(target)}.`)
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
          <Button type="submit" form="company-status-form" variant={isReject ? 'danger' : 'primary'} isLoading={busy}>{ACTION_LABEL[target] ?? 'Confirm'}</Button>
        </>
      }
    >
      <form id="company-status-form" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <p className="text-sm text-ink-soft">{who}</p>
        {(target === 'SELECTED' || isReject) && <p className="text-sm text-ink-soft">{isReject ? 'Rejection is final.' : 'Selection is final.'} The student is notified.</p>}
        <div>
          <label className="label" htmlFor="cs-remarks">{isReject ? 'Reason' : 'Remarks (optional)'}</label>
          <Textarea id="cs-remarks" rows={3} maxLength={1000} value={remarks} onChange={(e) => setRemarks(e.target.value)} error={fieldError ?? undefined} />
        </div>
      </form>
    </Modal>
  )
}
