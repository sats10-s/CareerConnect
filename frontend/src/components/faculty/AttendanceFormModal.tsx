import { useState, type FormEvent } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { facultyService } from '@/services/facultyService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { ATTENDANCE_STATUS, optionsFor } from '@/utils/statusMeta'
import { formatDate, todayIso } from '@/utils/dateFormat'
import type { AssignmentListItem, AttendanceRecord } from '@/types/coordination'
import type { AttendanceStatus } from '@/types/attendance'

type Mode =
  | { type: 'create'; assignments: AssignmentListItem[]; defaultAssignmentId?: number }
  | { type: 'edit'; record: AttendanceRecord }

interface AttendanceFormModalProps {
  open: boolean
  mode: Mode
  onClose: () => void
  onSaved: () => void
}

/**
 * Mark (POST /faculty/internship-assignments/{id}/attendance) or
 * correct (PATCH /faculty/attendance/{id}) one day's attendance.
 * Mirrors the server's own rules so the form can't be put in a state
 * the API will refuse for an obvious reason: times are only accepted
 * for PRESENT (the API rejects them for ABSENT/LEAVE), so those inputs
 * are disabled and cleared otherwise. Rules only the server can check
 * (date before the internship's start, a day already marked) come back
 * as messages and are shown inline, not guessed at here.
 */
/**
 * The form state lives in an inner component that only exists while the
 * modal is open, so every open starts from fresh initial values
 * (prefilled for an edit) on the very first render - no effect that
 * seeds state after paint and briefly flashes empty fields.
 */
export function AttendanceFormModal(props: AttendanceFormModalProps) {
  if (!props.open) return null
  return <AttendanceFormInner {...props} />
}

function AttendanceFormInner({ open, mode, onClose, onSaved }: AttendanceFormModalProps) {
  const isEdit = mode.type === 'edit'
  const editing = mode.type === 'edit' ? mode.record : null
  const [assignmentId, setAssignmentId] = useState(
    editing ? String(editing.internship_assignment_id) : mode.type === 'create' && mode.defaultAssignmentId ? String(mode.defaultAssignmentId) : '',
  )
  const [date, setDate] = useState(editing ? editing.attendance_date : todayIso())
  const [status, setStatus] = useState<AttendanceStatus>(editing ? editing.status : 'PRESENT')
  const [checkIn, setCheckIn] = useState(editing?.check_in_time?.slice(0, 5) ?? '')
  const [checkOut, setCheckOut] = useState(editing?.check_out_time?.slice(0, 5) ?? '')
  const [remarks, setRemarks] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const timesAllowed = status === 'PRESENT'
  const activeAssignments = mode.type === 'create' ? mode.assignments.filter((a) => a.status === 'ACTIVE') : []
  const chosen = mode.type === 'create' ? activeAssignments.find((a) => String(a.id) === assignmentId) : undefined

  function changeStatus(next: AttendanceStatus) {
    setStatus(next)
    if (next !== 'PRESENT') {
      setCheckIn('')
      setCheckOut('')
    }
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFormError(null)
    setFieldErrors({})

    if (!isEdit && !assignmentId) {
      setFieldErrors({ assignment: 'Choose which student this is for.' })
      return
    }
    if (timesAllowed && checkIn && checkOut && checkOut <= checkIn) {
      setFieldErrors({ check_out_time: 'Check-out must be after check-in.' })
      return
    }

    setSubmitting(true)
    try {
      const times = {
        check_in_time: timesAllowed && checkIn ? checkIn : null,
        check_out_time: timesAllowed && checkOut ? checkOut : null,
      }
      if (mode.type === 'edit') {
        await facultyService.updateAttendance(mode.record.id, { status, ...times, ...(remarks.trim() ? { remarks: remarks.trim() } : {}) })
        toast('success', 'Attendance updated.')
      } else {
        await facultyService.markAttendance(Number(assignmentId), {
          attendance_date: date,
          status,
          ...times,
          remarks: remarks.trim() || null,
        })
        toast('success', 'Attendance marked.')
      }
      onSaved()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save attendance.')
      setFieldErrors(parsed.fieldErrors)
      setFormError(formBanner(parsed))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? 'Correct attendance' : 'Mark attendance'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} type="button">
            Cancel
          </Button>
          <Button type="submit" form="attendance-form" isLoading={submitting}>
            {isEdit ? 'Save changes' : 'Mark attendance'}
          </Button>
        </>
      }
    >
      <form id="attendance-form" onSubmit={handleSubmit} className="space-y-4" noValidate>
        {formError && (
          <div role="alert" className="rounded-lg border border-error/20 bg-error-bg px-3.5 py-2.5 text-sm text-error">
            {formError}
          </div>
        )}

        {isEdit ? (
          <p className="text-sm text-ink-soft">
            {mode.record.student?.name} · {formatDate(mode.record.attendance_date)}
          </p>
        ) : (
          <>
            <div>
              <label className="label" htmlFor="att-assignment">Student</label>
              <Select id="att-assignment" value={assignmentId} onChange={(e) => setAssignmentId(e.target.value)} error={fieldErrors.assignment}>
                <option value="">Choose a student…</option>
                {activeAssignments.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.student?.name} — {a.internship?.company?.name ?? a.internship?.title}
                  </option>
                ))}
              </Select>
              {activeAssignments.length === 0 && (
                <p className="mt-1 text-xs text-ink-faint">Attendance can only be marked for internships that are currently active.</p>
              )}
            </div>
            <div>
              <label className="label" htmlFor="att-date">Date</label>
              <Input id="att-date" type="date" value={date} max={todayIso()} min={chosen?.started_at?.slice(0, 10)} onChange={(e) => setDate(e.target.value)} error={fieldErrors.attendance_date} />
            </div>
          </>
        )}

        <div>
          <label className="label" htmlFor="att-status">Status</label>
          <Select id="att-status" value={status} onChange={(e) => changeStatus(e.target.value as AttendanceStatus)} error={fieldErrors.status}>
            {optionsFor(ATTENDANCE_STATUS).map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label" htmlFor="att-in">Check-in</label>
            <Input id="att-in" type="time" value={checkIn} disabled={!timesAllowed} onChange={(e) => setCheckIn(e.target.value)} error={fieldErrors.check_in_time} />
          </div>
          <div>
            <label className="label" htmlFor="att-out">Check-out</label>
            <Input id="att-out" type="time" value={checkOut} disabled={!timesAllowed} onChange={(e) => setCheckOut(e.target.value)} error={fieldErrors.check_out_time} />
          </div>
        </div>
        {!timesAllowed && <p className="-mt-2 text-xs text-ink-faint">Times can only be recorded for days marked present.</p>}

        <div>
          <label className="label" htmlFor="att-remarks">{isEdit ? 'Remarks (leave blank to keep the existing note)' : 'Remarks (optional)'}</label>
          <Textarea id="att-remarks" rows={3} maxLength={1000} value={remarks} onChange={(e) => setRemarks(e.target.value)} error={fieldErrors.remarks} />
        </div>
      </form>
    </Modal>
  )
}
