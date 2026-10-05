import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { toast } from '@/components/ui/Toast'
import { studentDataService } from '@/services/studentDataService'
import { superAdminService } from '@/services/superAdminService'
import { formBanner, parseApiError } from '@/utils/apiError'

/**
 * The "create department" shortcut from an import's unknown-department
 * error: creates the department and maps the file's spelling to it, so a
 * re-check picks those rows up.
 */
export function CreateDepartmentModal({ spelling, onClose, onCreated }: { spelling: string; onClose: () => void; onCreated: () => void }) {
  const [name, setName] = useState('')
  const [code, setCode] = useState(spelling.replace(/[^A-Za-z0-9]/g, '').slice(0, 16).toUpperCase())
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [banner, setBanner] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setBanner(null)
    try {
      const department = await superAdminService.departments.create({ name: name.trim(), code: code.trim() })
      await studentDataService.aliases.create(spelling, department.id)
      toast('success', `${department.name} added.`)
      onCreated()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to create the department.')
      setErrors(parsed.fieldErrors)
      setBanner(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Create department"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button type="submit" form="create-dept" isLoading={busy}>Create and re-check</Button>
        </>
      }
    >
      <form id="create-dept" onSubmit={submit} className="space-y-4">
        <p className="text-sm text-ink-soft">The file says “{spelling}”. That spelling will map to the new department.</p>
        {banner && <p role="alert" className="text-sm text-error">{banner}</p>}
        <div>
          <label className="label" htmlFor="dept-name">Department name</label>
          <Input id="dept-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Robotics Engineering" error={errors.name} required />
        </div>
        <div>
          <label className="label" htmlFor="dept-code">Short code</label>
          <Input id="dept-code" value={code} onChange={(e) => setCode(e.target.value)} maxLength={16} error={errors.code} required />
        </div>
      </form>
    </Modal>
  )
}
