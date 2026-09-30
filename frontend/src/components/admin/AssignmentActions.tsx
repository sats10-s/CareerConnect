import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { useFacultyOptions } from '@/hooks/useAdminOptions'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { todayIso } from '@/utils/dateFormat'
import type { AssignmentDetail } from '@/types/coordination'

type Action = 'activate' | 'reassign' | 'complete' | 'cancel'

/**
 * Admin lifecycle actions for one internship assignment. Only the moves
 * InternshipAssignmentStatus::canTransitionTo() allows are offered:
 * ASSIGNED -> ACTIVE | CANCELLED, ACTIVE -> COMPLETED | CANCELLED
 * (reassigning a coordinator is separate and allowed while ASSIGNED or
 * ACTIVE). Faculty have none of these routes at all.
 */
export function AssignmentActions({ assignment, onChanged }: { assignment: AssignmentDetail; onChanged: () => void }) {
  const [action, setAction] = useState<Action | null>(null)
  const open = assignment.status === 'ASSIGNED' || assignment.status === 'ACTIVE'

  if (!open) return <p className="text-sm text-ink-soft">This assignment is {assignment.status.toLowerCase()} - no further actions.</p>

  return (
    <>
      <div className="flex flex-wrap justify-end gap-2">
        {assignment.status === 'ASSIGNED' && <Button onClick={() => setAction('activate')}>Activate</Button>}
        {assignment.status === 'ACTIVE' && <Button onClick={() => setAction('complete')}>Mark completed</Button>}
        <Button variant="secondary" onClick={() => setAction('reassign')}>Change coordinator</Button>
        <Button variant="danger" onClick={() => setAction('cancel')}>Cancel</Button>
      </div>
      {action && <ActionModal action={action} assignment={assignment} onClose={() => setAction(null)} onDone={onChanged} />}
    </>
  )
}

function ActionModal({ action, assignment, onClose, onDone }: { action: Action; assignment: AssignmentDetail; onClose: () => void; onDone: () => void }) {
  const faculty = useFacultyOptions()
  const [facultyId, setFacultyId] = useState('')
  const [date, setDate] = useState(todayIso())
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const titles: Record<Action, string> = { activate: 'Activate internship', reassign: 'Change faculty coordinator', complete: 'Mark internship completed', cancel: 'Cancel internship assignment' }
  const submits: Record<Action, string> = { activate: 'Activate', reassign: 'Change coordinator', complete: 'Mark completed', cancel: 'Cancel assignment' }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const local: Record<string, string> = {}
    if (action === 'reassign' && !facultyId) local.faculty_id = 'Choose the new coordinator.'
    if (action === 'cancel' && !text.trim()) local.reason = 'Give a reason - it is recorded in the history.'
    if (Object.keys(local).length) {
      setErrors(local)
      return
    }
    setErrors({})
    setBusy(true)
    try {
      const id = assignment.id
      if (action === 'activate') await adminService.assignments.activate(id, date)
      else if (action === 'reassign') await adminService.assignments.reassign(id, Number(facultyId), text.trim() || undefined)
      else if (action === 'complete') await adminService.assignments.complete(id, text.trim() || undefined, date)
      else await adminService.assignments.cancel(id, text.trim())
      toast('success', action === 'activate' ? 'Internship activated.' : action === 'reassign' ? 'Coordinator changed.' : action === 'complete' ? 'Marked as completed.' : 'Assignment cancelled.')
      onDone()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to update this assignment.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={titles[action]}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Back</Button>
          <Button type="submit" form="assignment-action" variant={action === 'cancel' ? 'danger' : 'primary'} isLoading={busy}>{submits[action]}</Button>
        </>
      }
    >
      <form id="assignment-action" onSubmit={submit} className="space-y-4" noValidate>
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <p className="text-sm text-ink-soft">{assignment.student?.name} · {assignment.internship?.title}</p>

        {action === 'reassign' && (
          <div>
            <label className="label" htmlFor="aa-faculty">New coordinator</label>
            <Select id="aa-faculty" value={facultyId} onChange={(e) => setFacultyId(e.target.value)} error={errors.faculty_id}>
              <option value="">{faculty.isLoading ? 'Loading faculty…' : 'Choose faculty…'}</option>
              {(faculty.data ?? []).filter((f) => f.faculty_id !== assignment.faculty?.id).map((f) => (
                <option key={f.faculty_id} value={f.faculty_id}>{f.faculty_name}{f.department ? ` — ${f.department}` : ''} ({f.active_students} active)</option>
              ))}
            </Select>
            {faculty.error && <p className="mt-1 text-xs text-error">{faculty.error}</p>}
          </div>
        )}
        {(action === 'activate' || action === 'complete') && (
          <div>
            <label className="label" htmlFor="aa-date">{action === 'activate' ? 'Start date' : 'End date'}</label>
            <Input id="aa-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} error={errors.started_at ?? errors.ended_at} />
          </div>
        )}
        <div>
          <label className="label" htmlFor="aa-text">{action === 'cancel' ? 'Reason' : 'Remarks (optional)'}</label>
          {action === 'activate' ? (
            <p className="text-xs text-ink-faint">Activating lets the faculty coordinator start marking attendance.</p>
          ) : (
            <Textarea id="aa-text" rows={3} maxLength={1000} value={text} onChange={(e) => setText(e.target.value)} error={errors.reason ?? errors.remarks} />
          )}
        </div>
      </form>
    </Modal>
  )
}
