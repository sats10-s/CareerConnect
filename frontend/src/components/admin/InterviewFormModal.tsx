import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { fromLocalInput, toLocalInput } from '@/utils/dateFormat'
import { INTERVIEW_MODE_LABEL } from '@/utils/statusMeta'
import type { InterviewDetail, InterviewMode } from '@/types/admin'

export type InterviewFormVariant =
  | { kind: 'schedule'; applicationId: number; who: string }
  | { kind: 'edit'; interview: InterviewDetail }
  | { kind: 'reschedule'; interview: InterviewDetail }

/**
 * One form for the three interview writes: schedule
 * (POST /admin/applications/{id}/interviews), edit the non-time details
 * (PATCH /admin/interviews/{id} - `scheduled_at` is prohibited there)
 * and reschedule (POST .../reschedule). The mode rules mirror the
 * backend's ValidatesInterviewMode: online needs a meeting link,
 * on-campus needs a venue, hybrid needs at least one of them. The time
 * is entered as local wall-clock time and sent as an unambiguous UTC
 * instant.
 */
export function InterviewFormModal({ variant, onClose, onSaved }: { variant: InterviewFormVariant; onClose: () => void; onSaved: () => void }) {
  const existing = variant.kind === 'schedule' ? null : variant.interview
  const showTime = variant.kind !== 'edit'
  const showPanel = variant.kind !== 'reschedule'

  const [round, setRound] = useState(existing ? String(existing.round) : '1')
  const [title, setTitle] = useState(existing?.title ?? '')
  const [when, setWhen] = useState(variant.kind === 'reschedule' ? toLocalInput(existing?.scheduled_at) : '')
  const [duration, setDuration] = useState(existing?.duration_minutes ? String(existing.duration_minutes) : '')
  const [mode, setMode] = useState<InterviewMode>(existing?.mode ?? 'ONLINE')
  const [url, setUrl] = useState(existing?.meeting_url ?? '')
  const [venue, setVenue] = useState(existing?.venue ?? '')
  const [interviewer, setInterviewer] = useState(existing?.interviewer_name ?? '')
  const [interviewerEmail, setInterviewerEmail] = useState(existing?.interviewer_email ?? '')
  const [notes, setNotes] = useState(existing?.panel_notes ?? '')
  const [remarks, setRemarks] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const needsUrl = mode === 'ONLINE' || mode === 'HYBRID'
  const needsVenue = mode === 'OFFLINE' || mode === 'HYBRID'

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const local: Record<string, string> = {}
    if (showTime && !when) local.scheduled_at = 'Choose the interview date and time.'
    if (mode === 'ONLINE' && !url.trim()) local.meeting_url = 'An online interview needs a meeting link.'
    if (mode === 'OFFLINE' && !venue.trim()) local.venue = 'An on-campus interview needs a venue.'
    if (mode === 'HYBRID' && !url.trim() && !venue.trim()) local.meeting_url = 'Add a meeting link, a venue, or both.'
    if (Object.keys(local).length) {
      setErrors(local)
      return
    }
    setErrors({})
    setBusy(true)
    try {
      const shared = {
        duration_minutes: duration ? Number(duration) : null,
        mode,
        meeting_url: needsUrl && url.trim() ? url.trim() : null,
        venue: needsVenue && venue.trim() ? venue.trim() : null,
      }
      if (variant.kind === 'schedule') {
        await adminService.applications.scheduleInterview(variant.applicationId, {
          round: Number(round) || 1,
          title: title.trim() || null,
          scheduled_at: fromLocalInput(when),
          ...shared,
          interviewer_name: interviewer.trim() || null,
          interviewer_email: interviewerEmail.trim() || null,
          panel_notes: notes.trim() || null,
        })
        toast('success', 'Interview scheduled.')
      } else if (variant.kind === 'edit') {
        await adminService.interviews.update(variant.interview.id, {
          round: Number(round) || 1,
          title: title.trim() || null,
          ...shared,
          interviewer_name: interviewer.trim() || null,
          interviewer_email: interviewerEmail.trim() || null,
          panel_notes: notes.trim() || null,
        })
        toast('success', 'Interview details saved.')
      } else {
        await adminService.interviews.reschedule(variant.interview.id, { scheduled_at: fromLocalInput(when), ...shared, remarks: remarks.trim() || null })
        toast('success', 'Interview rescheduled.')
      }
      onSaved()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save the interview.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  const titles = { schedule: 'Schedule interview', edit: 'Edit interview details', reschedule: 'Reschedule interview' }

  return (
    <Modal
      open
      onClose={onClose}
      title={titles[variant.kind]}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="interview-form" isLoading={busy}>{variant.kind === 'schedule' ? 'Schedule' : variant.kind === 'edit' ? 'Save changes' : 'Reschedule'}</Button>
        </>
      }
    >
      <form id="interview-form" onSubmit={submit} noValidate className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
        {error && <div role="alert" className="rounded-lg border border-error/20 bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <p className="text-sm text-ink-soft">
          {variant.kind === 'schedule' ? variant.who : `${variant.interview.student?.name ?? ''} · ${variant.interview.opportunity?.title ?? ''}`}
        </p>

        {variant.kind !== 'reschedule' && (
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="label" htmlFor="iv-round">Round</label>
              <Input id="iv-round" type="number" min={1} max={50} value={round} onChange={(e) => setRound(e.target.value)} error={errors.round} />
            </div>
            <div className="col-span-2">
              <label className="label" htmlFor="iv-title">Title (optional)</label>
              <Input id="iv-title" maxLength={255} placeholder="e.g. Technical round" value={title} onChange={(e) => setTitle(e.target.value)} error={errors.title} />
            </div>
          </div>
        )}

        {showTime && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="iv-when">Date and time</label>
              <Input id="iv-when" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} error={errors.scheduled_at} />
            </div>
            <div>
              <label className="label" htmlFor="iv-duration">Duration (minutes)</label>
              <Input id="iv-duration" type="number" min={1} max={480} value={duration} onChange={(e) => setDuration(e.target.value)} error={errors.duration_minutes} />
            </div>
          </div>
        )}
        {!showTime && (
          <div>
            <label className="label" htmlFor="iv-duration">Duration (minutes)</label>
            <Input id="iv-duration" type="number" min={1} max={480} value={duration} onChange={(e) => setDuration(e.target.value)} error={errors.duration_minutes} />
          </div>
        )}

        <div>
          <label className="label" htmlFor="iv-mode">Mode</label>
          <Select id="iv-mode" value={mode} onChange={(e) => setMode(e.target.value as InterviewMode)} error={errors.mode}>
            {Object.entries(INTERVIEW_MODE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
        </div>
        {needsUrl && (
          <div>
            <label className="label" htmlFor="iv-url">Meeting link</label>
            <Input id="iv-url" type="url" maxLength={500} placeholder="https://" value={url} onChange={(e) => setUrl(e.target.value)} error={errors.meeting_url} />
          </div>
        )}
        {needsVenue && (
          <div>
            <label className="label" htmlFor="iv-venue">Venue</label>
            <Input id="iv-venue" maxLength={255} value={venue} onChange={(e) => setVenue(e.target.value)} error={errors.venue} />
          </div>
        )}

        {variant.kind !== 'reschedule' && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="iv-name">Interviewer</label>
              <Input id="iv-name" maxLength={255} value={interviewer} onChange={(e) => setInterviewer(e.target.value)} error={errors.interviewer_name} />
            </div>
            <div>
              <label className="label" htmlFor="iv-email">Interviewer email</label>
              <Input id="iv-email" type="email" maxLength={255} value={interviewerEmail} onChange={(e) => setInterviewerEmail(e.target.value)} error={errors.interviewer_email} />
            </div>
          </div>
        )}
        {showPanel && (
          <div>
            <label className="label" htmlFor="iv-notes">Panel notes (optional)</label>
            <Textarea id="iv-notes" rows={3} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} error={errors.panel_notes} />
          </div>
        )}
        {variant.kind === 'reschedule' && (
          <div>
            <label className="label" htmlFor="iv-remarks">Reason for the change (optional)</label>
            <Textarea id="iv-remarks" rows={3} maxLength={1000} value={remarks} onChange={(e) => setRemarks(e.target.value)} error={errors.remarks} />
          </div>
        )}
      </form>
    </Modal>
  )
}
