import { useEffect, useState, type FormEvent } from 'react'
import { isAxiosError } from 'axios'
import { Pencil, Save, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useAuth } from '@/hooks/useAuth'
import { studentService } from '@/services/studentService'
import { metaService } from '@/services/metaService'
import type { ApiValidationError, StudentProfile, StudentProfileUpdatePayload } from '@/types/auth'
import type { Batch, Department } from '@/types/meta'

interface FormState {
  roll_number: string
  department_id: string
  batch_id: string
  year: string
  cgpa: string
  backlogs: string
  phone: string
}

const YEAR_OPTIONS = [1, 2, 3, 4] as const

export function StudentProfilePage() {
  const { user, refresh } = useAuth()
  const [departments, setDepartments] = useState<Department[]>([])
  const [batches, setBatches] = useState<Batch[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [isEditing, setIsEditing] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [form, setForm] = useState<FormState | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  useEffect(() => {
    metaService.departments().then(setDepartments).catch(() => setDepartments([]))
    metaService.batches().then(setBatches).catch(() => setBatches([]))
    studentService
      .getProfile()
      .then((profile) => seedForm(profile.student))
      .finally(() => setIsLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function seedForm(student: StudentProfile | null | undefined) {
    if (!student) return
    setForm({
      roll_number: student.roll_number ?? '',
      department_id: student.department_id ? String(student.department_id) : '',
      batch_id: student.batch_id ? String(student.batch_id) : '',
      year: student.year ? String(student.year) : '',
      cgpa: student.cgpa === null || student.cgpa === undefined ? '' : String(student.cgpa),
      backlogs: student.backlogs === null || student.backlogs === undefined ? '' : String(student.backlogs),
      phone: student.phone ?? '',
    })
  }

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => (prev ? { ...prev, [key]: value } : prev))
  }

  function fieldError(key: string) {
    return errors[key]?.[0]
  }

  function startEditing() {
    setSuccessMessage(null)
    setFormError(null)
    setErrors({})
    setIsEditing(true)
  }

  function cancelEditing() {
    setIsEditing(false)
    setErrors({})
    setFormError(null)
    if (user?.student) seedForm(user.student)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    if (!form) return
    setFormError(null)
    setErrors({})
    setIsSaving(true)
    try {
      const payload: StudentProfileUpdatePayload = {
        roll_number: form.roll_number,
        department_id: Number(form.department_id),
        batch_id: Number(form.batch_id),
        year: Number(form.year),
        cgpa: form.cgpa === '' ? null : Number(form.cgpa),
        backlogs: form.backlogs === '' ? null : Number(form.backlogs),
        phone: form.phone === '' ? null : form.phone,
      }
      const updated = await studentService.updateProfile(payload)
      seedForm(updated.student)
      await refresh()
      setIsEditing(false)
      setSuccessMessage('Profile updated successfully.')
    } catch (err) {
      if (isAxiosError<ApiValidationError>(err) && err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        setFormError(err.response.data.message)
      } else {
        setFormError('Unable to update your profile. Please try again.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  if (isLoading) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-gray-900">Your profile</h2>
        <p className="mt-1 text-sm text-gray-500">{user?.name} · {user?.email}</p>
      </div>

      {successMessage && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-700">
          {successMessage}
        </div>
      )}

      <Card>
        <CardHeader
          title="Academic & contact details"
          subtitle="Roll number, department, year, batch, CGPA, backlogs, and phone"
          action={
            !isEditing ? (
              <Button type="button" variant="secondary" onClick={startEditing}>
                <Pencil className="h-4 w-4" />
                Edit
              </Button>
            ) : undefined
          }
        />

        {!form ? (
          <p className="px-5 py-8 text-center text-sm text-gray-500">Profile details are not available right now.</p>
        ) : !isEditing ? (
          <div className="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-2">
            <ProfileField label="Roll number" value={user?.student?.roll_number ?? '—'} />
            <ProfileField label="Department" value={user?.student?.department ?? '—'} />
            <ProfileField label="Year" value={user?.student?.year ? `Year ${user.student.year}` : '—'} />
            <ProfileField label="Batch" value={user?.student?.batch ?? '—'} />
            <ProfileField label="CGPA" value={user?.student?.cgpa ?? '—'} />
            <ProfileField label="Backlogs" value={user?.student?.backlogs ?? '—'} />
            <ProfileField label="Phone" value={user?.student?.phone ?? '—'} />
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4 px-5 py-5">
            {formError && (
              <div className="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{formError}</div>
            )}

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label" htmlFor="roll_number">Roll number</label>
                <input
                  id="roll_number"
                  required
                  className="input"
                  value={form.roll_number}
                  onChange={(e) => update('roll_number', e.target.value)}
                />
                {fieldError('roll_number') && <p className="mt-1 text-xs text-red-600">{fieldError('roll_number')}</p>}
              </div>
              <div>
                <label className="label" htmlFor="phone">Phone</label>
                <input id="phone" className="input" value={form.phone} onChange={(e) => update('phone', e.target.value)} />
                {fieldError('phone') && <p className="mt-1 text-xs text-red-600">{fieldError('phone')}</p>}
              </div>
            </div>

            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="label" htmlFor="department_id">Department</label>
                <select
                  id="department_id"
                  required
                  className="input"
                  value={form.department_id}
                  onChange={(e) => update('department_id', e.target.value)}
                >
                  <option value="">Select…</option>
                  {departments.map((d) => (
                    <option key={d.id} value={d.id}>{d.code}</option>
                  ))}
                </select>
                {fieldError('department_id') && <p className="mt-1 text-xs text-red-600">{fieldError('department_id')}</p>}
              </div>
              <div>
                <label className="label" htmlFor="batch_id">Batch</label>
                <select
                  id="batch_id"
                  required
                  className="input"
                  value={form.batch_id}
                  onChange={(e) => update('batch_id', e.target.value)}
                >
                  <option value="">Select…</option>
                  {batches.map((b) => (
                    <option key={b.id} value={b.id}>{b.name}</option>
                  ))}
                </select>
                {fieldError('batch_id') && <p className="mt-1 text-xs text-red-600">{fieldError('batch_id')}</p>}
              </div>
              <div>
                <label className="label" htmlFor="year">Year</label>
                <select id="year" required className="input" value={form.year} onChange={(e) => update('year', e.target.value)}>
                  <option value="">Select…</option>
                  {YEAR_OPTIONS.map((y) => (
                    <option key={y} value={y}>Year {y}</option>
                  ))}
                </select>
                {fieldError('year') && <p className="mt-1 text-xs text-red-600">{fieldError('year')}</p>}
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
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
                  onChange={(e) => update('cgpa', e.target.value)}
                />
                {fieldError('cgpa') && <p className="mt-1 text-xs text-red-600">{fieldError('cgpa')}</p>}
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
                  onChange={(e) => update('backlogs', e.target.value)}
                />
                {fieldError('backlogs') && <p className="mt-1 text-xs text-red-600">{fieldError('backlogs')}</p>}
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
    </div>
  )
}

function ProfileField({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-gray-400">{label}</p>
      <p className="mt-1 text-sm font-medium text-gray-900">{value}</p>
    </div>
  )
}
