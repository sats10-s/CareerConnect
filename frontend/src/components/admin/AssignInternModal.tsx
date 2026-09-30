import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { useFacultyOptions } from '@/hooks/useAdminOptions'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'

/**
 * Create an internship assignment (POST /admin/internship-assignments).
 * The backend only accepts a SELECTED application for an internship, so
 * the student picker is exactly that set, minus students who already
 * have an assignment for the same internship (matched on student +
 * internship, since assignment rows don't carry the application id).
 */
export function AssignInternModal({ onClose, onSaved }: { onClose: () => void; onSaved: (id: number) => void }) {
  const faculty = useFacultyOptions()
  const candidates = useApiResource(async () => {
    const [applications, assignments] = await Promise.all([
      adminService.applications.list({ status: 'SELECTED', type: 'internship', per_page: 100 }),
      adminService.assignments.list({ per_page: 100 }),
    ])
    const taken = new Set(assignments.items.map((a) => `${a.student?.id}:${a.internship?.id}`))
    return applications.items.filter((app) => !taken.has(`${app.student?.id}:${app.opportunity?.id}`))
  }, 'assign-candidates')

  const [applicationId, setApplicationId] = useState('')
  const [facultyId, setFacultyId] = useState('')
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const local: Record<string, string> = {}
    if (!applicationId) local.application_id = 'Choose the selected student.'
    if (!facultyId) local.faculty_id = 'Choose a faculty coordinator.'
    if (Object.keys(local).length) {
      setErrors(local)
      return
    }
    setErrors({})
    setBusy(true)
    try {
      const created = await adminService.assignments.create({ application_id: Number(applicationId), faculty_id: Number(facultyId), notes: notes.trim() || null })
      toast('success', 'Assignment created. Activate it when the internship starts.')
      onSaved(created.id)
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to create the assignment.')
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
      title="Assign a student"
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="assign-form" isLoading={busy}>Create assignment</Button>
        </>
      }
    >
      <form id="assign-form" onSubmit={submit} className="space-y-4" noValidate>
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <div>
          <label className="label" htmlFor="as-app">Selected student</label>
          <Select id="as-app" value={applicationId} onChange={(e) => setApplicationId(e.target.value)} error={errors.application_id}>
            <option value="">{candidates.isLoading ? 'Loading…' : 'Choose a student…'}</option>
            {(candidates.data ?? []).map((a) => (
              <option key={a.id} value={a.id}>{a.student?.name} — {a.opportunity?.title} ({a.opportunity?.company?.name})</option>
            ))}
          </Select>
          {candidates.error && <p className="mt-1 text-xs text-error">{candidates.error}</p>}
          {!candidates.isLoading && !candidates.error && (candidates.data ?? []).length === 0 && (
            <p className="mt-1 text-xs text-ink-faint">No one is waiting for a coordinator. A student appears here once their internship application is marked Selected.</p>
          )}
        </div>
        <div>
          <label className="label" htmlFor="as-faculty">Faculty coordinator</label>
          <Select id="as-faculty" value={facultyId} onChange={(e) => setFacultyId(e.target.value)} error={errors.faculty_id}>
            <option value="">{faculty.isLoading ? 'Loading faculty…' : 'Choose faculty…'}</option>
            {(faculty.data ?? []).map((f) => (
              <option key={f.faculty_id} value={f.faculty_id}>{f.faculty_name}{f.department ? ` — ${f.department}` : ''} ({f.active_students} active)</option>
            ))}
          </Select>
          {faculty.error && <p className="mt-1 text-xs text-error">{faculty.error}</p>}
        </div>
        <div>
          <label className="label" htmlFor="as-notes">Notes (optional)</label>
          <Textarea id="as-notes" rows={3} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} error={errors.notes} />
        </div>
      </form>
    </Modal>
  )
}
