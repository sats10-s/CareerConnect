import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { adminService } from '@/services/adminService'
import { parseApiError } from '@/utils/apiError'

/**
 * "Update academics" (Placement Admin and Super Admin): a new CGPA and/or
 * backlog count with a required reason. It goes into the student's history,
 * they get a notification, and eligibility uses the new numbers at once.
 */
export function UpdateAcademicsModal({
  studentId,
  current,
  onClose,
  onSaved,
}: {
  studentId: number
  current: { cgpa: number | null; backlogs: number | null }
  onClose: () => void
  onSaved: () => void
}) {
  const [cgpa, setCgpa] = useState(current.cgpa === null ? '' : current.cgpa.toFixed(2))
  const [backlogs, setBacklogs] = useState(current.backlogs === null ? '' : String(current.backlogs))
  const [semester, setSemester] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    const local: Record<string, string> = {}
    const cgpaValue = cgpa.trim() === '' ? null : Number(cgpa)
    const backlogValue = backlogs.trim() === '' ? null : Number(backlogs)
    if (cgpaValue !== null && (!Number.isFinite(cgpaValue) || cgpaValue < 0 || cgpaValue > 10)) local.cgpa = 'CGPA is between 0 and 10.'
    if (backlogValue !== null && (!Number.isInteger(backlogValue) || backlogValue < 0)) local.backlogs = 'Backlogs is a whole number, 0 or more.'
    if (cgpaValue === null && backlogValue === null) local.cgpa = 'Enter a new CGPA or backlog count.'
    if (reason.trim().length < 3) local.reason = "Say why - it is kept in the student's history."
    setErrors(local)
    if (Object.keys(local).length) return

    setBusy(true)
    try {
      const result = await adminService.students.updateAcademics(studentId, {
        cgpa: cgpaValue,
        backlogs: backlogValue,
        semester_label: semester.trim() || null,
        reason: reason.trim(),
      })
      toast(result.changed ? 'success' : 'info', result.message)
      onSaved()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to update academics.')
      setErrors(Object.keys(parsed.fieldErrors).length ? parsed.fieldErrors : { reason: parsed.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Update academics"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button type="submit" form="academics-form" isLoading={busy}>Save and notify student</Button>
        </>
      }
    >
      <form id="academics-form" onSubmit={submit} className="space-y-4" noValidate>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label" htmlFor="ua-cgpa">CGPA</label>
            <Input id="ua-cgpa" type="number" inputMode="decimal" step="0.01" min={0} max={10} value={cgpa} onChange={(e) => setCgpa(e.target.value)} error={errors.cgpa} />
          </div>
          <div>
            <label className="label" htmlFor="ua-backlogs">Backlogs</label>
            <Input id="ua-backlogs" type="number" inputMode="numeric" min={0} value={backlogs} onChange={(e) => setBacklogs(e.target.value)} error={errors.backlogs} />
          </div>
        </div>
        <div>
          <label className="label" htmlFor="ua-semester">Semester (optional)</label>
          <Input id="ua-semester" placeholder="e.g. 3-2" maxLength={40} value={semester} onChange={(e) => setSemester(e.target.value)} />
        </div>
        <div>
          <label className="label" htmlFor="ua-reason">Reason</label>
          <Textarea id="ua-reason" rows={3} maxLength={500} placeholder="e.g. Revaluation result from the exam cell" value={reason} onChange={(e) => setReason(e.target.value)} error={errors.reason} />
        </div>
        <p className="text-xs text-ink-faint">A blank field keeps its current value. Existing applications stay as they are; any that no longer meet criteria are flagged on Applications.</p>
      </form>
    </Modal>
  )
}
