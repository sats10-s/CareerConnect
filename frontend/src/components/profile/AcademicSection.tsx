import { useEffect, useState, type FormEvent } from 'react'
import { Pencil, Save, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { metaService } from '@/services/metaService'
import { studentProfileService } from '@/services/studentProfileService'
import type { StudentProfileData } from '@/types/studentProfile'
import type { Batch, Department } from '@/types/meta'
import { describeFailure, ErrorBanner, FieldError, fieldError } from './shared'

interface FormState {
  roll_number: string
  department_id: string
  batch_id: string
  year: string
  cgpa: string
  backlogs: string
}

const YEAR_OPTIONS = [1, 2, 3, 4] as const

function toForm(profile: StudentProfileData): FormState {
  return {
    roll_number: profile.roll_number ?? '',
    department_id: profile.department_id ? String(profile.department_id) : '',
    batch_id: profile.batch_id ? String(profile.batch_id) : '',
    year: profile.year ? String(profile.year) : '',
    cgpa: profile.cgpa === null ? '' : String(profile.cgpa),
    backlogs: profile.backlogs === null ? '' : String(profile.backlogs),
  }
}

/** Roll number, department, batch, year, CGPA, backlogs - department/batch options come from the live meta endpoints, never hardcoded lists. */
export function AcademicSection({
  profile,
  onProfileChange,
}: {
  profile: StudentProfileData
  onProfileChange: (profile: StudentProfileData) => void
}) {
  const [departments, setDepartments] = useState<Department[]>([])
  const [batches, setBatches] = useState<Batch[]>([])
  const [isEditing, setIsEditing] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [form, setForm] = useState<FormState>(() => toForm(profile))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  useEffect(() => {
    metaService.departments().then(setDepartments).catch(() => setDepartments([]))
    metaService.batches().then(setBatches).catch(() => setBatches([]))
  }, [])

  function startEditing() {
    setForm(toForm(profile))
    setErrors({})
    setFormError(null)
    setIsEditing(true)
  }

  function cancelEditing() {
    setIsEditing(false)
    setErrors({})
    setFormError(null)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setIsSaving(true)
    setErrors({})
    setFormError(null)
    try {
      const payload = studentProfileService.toUpdatePayload(profile, {
        roll_number: form.roll_number,
        department_id: Number(form.department_id),
        batch_id: Number(form.batch_id),
        year: Number(form.year),
        cgpa: form.cgpa === '' ? null : Number(form.cgpa),
        backlogs: form.backlogs === '' ? null : Number(form.backlogs),
      })
      const updated = await studentProfileService.updateProfile(payload)
      onProfileChange(updated)
      setIsEditing(false)
    } catch (err) {
      const failure = describeFailure(err, 'Unable to update your academic details. Please try again.')
      setErrors(failure.errors)
      setFormError(failure.message)
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Academic information"
        subtitle="Roll number, department, batch, year, CGPA, and backlogs"
        action={
          !isEditing ? (
            <Button type="button" variant="secondary" onClick={startEditing}>
              <Pencil className="h-4 w-4" />
              Edit
            </Button>
          ) : undefined
        }
      />

      {!isEditing ? (
        <div className="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-3">
          <ReadOnlyField label="Roll number" value={profile.roll_number || '—'} />
          <ReadOnlyField label="Department" value={profile.department ?? '—'} />
          <ReadOnlyField label="Batch" value={profile.batch ?? '—'} />
          <ReadOnlyField label="Year" value={profile.year ? `Year ${profile.year}` : '—'} />
          <ReadOnlyField label="CGPA" value={profile.cgpa ?? '—'} />
          <ReadOnlyField label="Backlogs" value={profile.backlogs ?? '—'} />
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4 px-5 py-5">
          {formError && <ErrorBanner message={formError} />}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="roll_number">Roll number</label>
              <input
                id="roll_number"
                required
                className="input"
                value={form.roll_number}
                onChange={(e) => setForm((prev) => ({ ...prev, roll_number: e.target.value }))}
              />
              <FieldError message={fieldError(errors, 'roll_number')} />
            </div>
            <div>
              <label className="label" htmlFor="year">Year</label>
              <select
                id="year"
                required
                className="input"
                value={form.year}
                onChange={(e) => setForm((prev) => ({ ...prev, year: e.target.value }))}
              >
                <option value="">Select…</option>
                {YEAR_OPTIONS.map((y) => (
                  <option key={y} value={y}>Year {y}</option>
                ))}
              </select>
              <FieldError message={fieldError(errors, 'year')} />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="department_id">Department</label>
              <select
                id="department_id"
                required
                className="input"
                value={form.department_id}
                onChange={(e) => setForm((prev) => ({ ...prev, department_id: e.target.value }))}
              >
                <option value="">Select…</option>
                {departments.map((d) => (
                  <option key={d.id} value={d.id}>{d.name}</option>
                ))}
              </select>
              <FieldError message={fieldError(errors, 'department_id')} />
            </div>
            <div>
              <label className="label" htmlFor="batch_id">Batch</label>
              <select
                id="batch_id"
                required
                className="input"
                value={form.batch_id}
                onChange={(e) => setForm((prev) => ({ ...prev, batch_id: e.target.value }))}
              >
                <option value="">Select…</option>
                {batches.map((b) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </select>
              <FieldError message={fieldError(errors, 'batch_id')} />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="cgpa">CGPA (optional)</label>
              <input
                id="cgpa"
                type="number"
                step="0.01"
                min="0"
                max="10"
                className="input"
                value={form.cgpa}
                onChange={(e) => setForm((prev) => ({ ...prev, cgpa: e.target.value }))}
              />
              <FieldError message={fieldError(errors, 'cgpa')} />
            </div>
            <div>
              <label className="label" htmlFor="backlogs">Backlogs (optional)</label>
              <input
                id="backlogs"
                type="number"
                step="1"
                min="0"
                className="input"
                value={form.backlogs}
                onChange={(e) => setForm((prev) => ({ ...prev, backlogs: e.target.value }))}
              />
              <FieldError message={fieldError(errors, 'backlogs')} />
            </div>
          </div>

          <div className="flex gap-3">
            <Button type="submit" isLoading={isSaving}>
              <Save className="h-4 w-4" />
              Save changes
            </Button>
            <Button type="button" variant="secondary" onClick={cancelEditing} disabled={isSaving}>
              <X className="h-4 w-4" />
              Cancel
            </Button>
          </div>
        </form>
      )}
    </Card>
  )
}

function ReadOnlyField({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</p>
      <p className="mt-1 text-sm font-medium text-ink">{value}</p>
    </div>
  )
}
