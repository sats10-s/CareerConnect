import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { studentProfileService } from '@/services/studentProfileService'
import { parseApiError } from '@/utils/apiError'
import type { StudentProfileData } from '@/types/studentProfile'
import { CORRECTION_FIELDS, type CorrectionField } from './collegeFormat'

/** Tells the placement office a college-record value is wrong. The value itself only changes when they fix it. */
export function ReportCorrectionModal({
  initialField,
  onClose,
  onProfileChange,
}: {
  initialField: CorrectionField
  onClose: () => void
  onProfileChange: (profile: StudentProfileData) => void
}) {
  const [field, setField] = useState<CorrectionField>(initialField)
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (note.trim().length < 5) return setErrors({ note: 'Tell the placement office what the correct value is.' })
    setBusy(true)
    setErrors({})
    try {
      onProfileChange(await studentProfileService.reportCorrection(field, note.trim()))
      toast('success', 'Sent to the placement office. They will update your record if it needs fixing.')
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to send this right now.')
      setErrors(Object.keys(parsed.fieldErrors).length ? parsed.fieldErrors : { note: parsed.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Report a correction"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button type="submit" form="correction-form" isLoading={busy}>Send to placement office</Button>
        </>
      }
    >
      <form id="correction-form" onSubmit={submit} className="space-y-4">
        <p className="text-sm text-ink-soft">These details come from your college's records, so you can't change them here. Tell the placement office what's wrong and they'll check it.</p>
        <div>
          <label className="label" htmlFor="correction-field">What is wrong?</label>
          <Select id="correction-field" value={field} onChange={(e) => setField(e.target.value as CorrectionField)} error={errors.field}>
            {CORRECTION_FIELDS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
          </Select>
        </div>
        <div>
          <label className="label" htmlFor="correction-note">Correct value and details</label>
          <Textarea id="correction-note" rows={4} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. My CGPA after the 3-2 revaluation is 8.40." error={errors.note} />
        </div>
      </form>
    </Modal>
  )
}
