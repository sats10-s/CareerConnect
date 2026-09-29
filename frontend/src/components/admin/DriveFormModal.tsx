import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { useCompanyOptions } from '@/hooks/useAdminOptions'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { fromLocalInput, toLocalInput } from '@/utils/dateFormat'
import { DRIVE_TYPE_LABEL } from '@/utils/statusMeta'
import type { DriveDetail, DriveType } from '@/types/admin'

/**
 * Create/edit a placement drive (POST/PATCH /admin/placement-drives).
 * Status is not a field (publish/close/cancel are drawer actions).
 * The company is locked once a drive has left DRAFT - the backend
 * refuses to change it afterwards, so the select is disabled rather
 * than offered and refused. The "deadline must not be after the drive
 * date" rule is enforced by the API; its message is shown on the field.
 */
export function DriveFormModal({ existing, onClose, onSaved }: { existing?: DriveDetail; onClose: () => void; onSaved: (drive: DriveDetail) => void }) {
  const companies = useCompanyOptions()
  const [companyId, setCompanyId] = useState(existing?.company ? String(existing.company.id) : '')
  const [title, setTitle] = useState(existing?.title ?? '')
  const [description, setDescription] = useState(existing?.description ?? '')
  const [driveType, setDriveType] = useState<DriveType>(existing?.drive_type ?? 'CAMPUS_PLACEMENT')
  const [venue, setVenue] = useState(existing?.venue ?? '')
  const [scheduledAt, setScheduledAt] = useState(toLocalInput(existing?.scheduled_at))
  const [deadline, setDeadline] = useState(toLocalInput(existing?.application_deadline))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const companyLocked = Boolean(existing && existing.status !== 'DRAFT')

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const local: Record<string, string> = {}
    if (!companyId) local.company_id = 'Choose the company hosting this drive.'
    if (!title.trim()) local.title = 'Give the drive a title.'
    if (Object.keys(local).length) {
      setErrors(local)
      return
    }
    setErrors({})
    setBusy(true)
    try {
      const payload = {
        company_id: Number(companyId),
        title: title.trim(),
        description: description.trim() || null,
        drive_type: driveType,
        venue: venue.trim() || null,
        scheduled_at: scheduledAt ? fromLocalInput(scheduledAt) : null,
        application_deadline: deadline ? fromLocalInput(deadline) : null,
      }
      const saved = existing ? await adminService.drives.update(existing.id, payload) : await adminService.drives.create(payload)
      toast('success', existing ? 'Changes saved.' : 'Drive created as a draft.')
      onSaved(saved)
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save this drive.')
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
      title={existing ? 'Edit placement drive' : 'New placement drive'}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="drive-form" isLoading={busy}>{existing ? 'Save changes' : 'Create drive'}</Button>
        </>
      }
    >
      <form id="drive-form" onSubmit={submit} noValidate className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
        {error && <div role="alert" className="rounded-lg border border-error/20 bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <div>
          <label className="label" htmlFor="dr-company">Company</label>
          <Select id="dr-company" value={companyId} disabled={companyLocked} onChange={(e) => setCompanyId(e.target.value)} error={errors.company_id}>
            <option value="">{companies.isLoading ? 'Loading companies…' : 'Choose a company…'}</option>
            {(companies.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            {existing?.company && !(companies.data ?? []).some((c) => c.id === existing.company?.id) && <option value={existing.company.id}>{existing.company.name}</option>}
          </Select>
          {companyLocked && <p className="mt-1 text-xs text-ink-faint">The company can't be changed once a drive has been published.</p>}
        </div>
        <div>
          <label className="label" htmlFor="dr-title">Title</label>
          <Input id="dr-title" maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} error={errors.title} />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="dr-type">Drive type</label>
            <Select id="dr-type" value={driveType} onChange={(e) => setDriveType(e.target.value as DriveType)} error={errors.drive_type}>
              {Object.entries(DRIVE_TYPE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </div>
          <div>
            <label className="label" htmlFor="dr-venue">Venue</label>
            <Input id="dr-venue" maxLength={255} value={venue} onChange={(e) => setVenue(e.target.value)} error={errors.venue} />
          </div>
          <div>
            <label className="label" htmlFor="dr-when">Drive date and time</label>
            <Input id="dr-when" type="datetime-local" value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} error={errors.scheduled_at} />
          </div>
          <div>
            <label className="label" htmlFor="dr-deadline">Application deadline</label>
            <Input id="dr-deadline" type="datetime-local" value={deadline} onChange={(e) => setDeadline(e.target.value)} error={errors.application_deadline} />
          </div>
        </div>
        <div>
          <label className="label" htmlFor="dr-desc">Description</label>
          <Textarea id="dr-desc" rows={4} value={description} onChange={(e) => setDescription(e.target.value)} error={errors.description} />
        </div>
      </form>
    </Modal>
  )
}
