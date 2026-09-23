import { useState, type FormEvent } from 'react'
import { Briefcase, Pencil, Plus, Save, Trash2, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { IconButton } from '@/components/ui/IconButton'
import { Spinner } from '@/components/ui/Spinner'
import { studentProfileService } from '@/services/studentProfileService'
import type { ExperiencePayload, StudentExperience, StudentProfileData } from '@/types/studentProfile'
import { describeFailure, ErrorBanner, FieldError, fieldError, formatDate } from './shared'

interface FormState {
  organization: string
  role: string
  start_date: string
  end_date: string
  description: string
}

const EMPTY_FORM: FormState = { organization: '', role: '', start_date: '', end_date: '', description: '' }
const TODAY = new Date().toISOString().slice(0, 10)

function toForm(experience: StudentExperience): FormState {
  return {
    organization: experience.organization,
    role: experience.role,
    start_date: experience.start_date,
    end_date: experience.end_date ?? '',
    description: experience.description ?? '',
  }
}

function toPayload(form: FormState): ExperiencePayload {
  return {
    organization: form.organization,
    role: form.role,
    start_date: form.start_date,
    end_date: form.end_date === '' ? null : form.end_date,
    description: form.description === '' ? null : form.description,
  }
}

/** Work/internship experience - each its own record (student_experiences), with Add/Edit/Delete per item. An ongoing role can omit its end date. */
export function ExperienceSection({
  profile,
  onProfileChange,
}: {
  profile: StudentProfileData
  onProfileChange: (profile: StudentProfileData) => void
}) {
  const [isAddingNew, setIsAddingNew] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  return (
    <Card>
      <CardHeader
        title="Experience"
        subtitle="Internships and work experience"
        action={
          !isAddingNew ? (
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setEditingId(null)
                setIsAddingNew(true)
              }}
            >
              <Plus className="h-4 w-4" />
              Add experience
            </Button>
          ) : undefined
        }
      />

      <div className="divide-y divide-border px-5">
        {isAddingNew && (
          <div className="py-5">
            <ExperienceForm
              initial={EMPTY_FORM}
              submitLabel="Add experience"
              onCancel={() => setIsAddingNew(false)}
              onSubmit={async (payload) => {
                const updated = await studentProfileService.addExperience(payload)
                onProfileChange(updated)
                setIsAddingNew(false)
              }}
            />
          </div>
        )}

        {profile.experiences.length === 0 && !isAddingNew ? (
          <p className="py-8 text-center text-sm text-ink-faint">No experience added yet.</p>
        ) : (
          profile.experiences.map((experience) =>
            editingId === experience.id ? (
              <div key={experience.id} className="py-5">
                <ExperienceForm
                  initial={toForm(experience)}
                  submitLabel="Save changes"
                  onCancel={() => setEditingId(null)}
                  onSubmit={async (payload) => {
                    const updated = await studentProfileService.updateExperience(experience.id, payload)
                    onProfileChange(updated)
                    setEditingId(null)
                  }}
                />
              </div>
            ) : (
              <div key={experience.id} className="flex items-start justify-between gap-4 py-4">
                <div className="flex min-w-0 gap-3">
                  <Briefcase className="mt-0.5 h-4 w-4 shrink-0 text-indigo" aria-hidden="true" />
                  <div className="min-w-0">
                    <h4 className="text-sm font-semibold text-ink">
                      {experience.role} · {experience.organization}
                    </h4>
                    <p className="mt-0.5 text-xs text-ink-faint">
                      {formatDate(experience.start_date)} – {experience.end_date ? formatDate(experience.end_date) : 'Present'}
                    </p>
                    {experience.description && <p className="mt-1.5 text-sm text-ink-soft">{experience.description}</p>}
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  <IconButton
                    icon={Pencil}
                    label={`Edit ${experience.role} at ${experience.organization}`}
                    onClick={() => {
                      setIsAddingNew(false)
                      setEditingId(experience.id)
                    }}
                  />
                  {deletingId === experience.id ? (
                    <Spinner className="h-5 w-5" />
                  ) : (
                    <IconButton
                      icon={Trash2}
                      label={`Delete ${experience.role} at ${experience.organization}`}
                      onClick={async () => {
                        if (!window.confirm(`Delete "${experience.role}" at "${experience.organization}"? This cannot be undone.`)) return
                        setDeletingId(experience.id)
                        try {
                          const updated = await studentProfileService.deleteExperience(experience.id)
                          onProfileChange(updated)
                        } finally {
                          setDeletingId(null)
                        }
                      }}
                    />
                  )}
                </div>
              </div>
            )
          )
        )}
      </div>
    </Card>
  )
}

function ExperienceForm({
  initial,
  submitLabel,
  onCancel,
  onSubmit,
}: {
  initial: FormState
  submitLabel: string
  onCancel: () => void
  onSubmit: (payload: ExperiencePayload) => Promise<void>
}) {
  const [form, setForm] = useState<FormState>(initial)
  const [isSaving, setIsSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setIsSaving(true)
    setErrors({})
    setFormError(null)
    try {
      await onSubmit(toPayload(form))
    } catch (err) {
      const failure = describeFailure(err, 'Unable to save this experience. Please try again.')
      setErrors(failure.errors)
      setFormError(failure.message)
      setIsSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 rounded-lg border border-border bg-sidebar p-4">
      {formError && <ErrorBanner message={formError} />}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="experience_organization">Organization</label>
          <input
            id="experience_organization"
            required
            maxLength={150}
            className="input"
            value={form.organization}
            onChange={(e) => setForm((prev) => ({ ...prev, organization: e.target.value }))}
          />
          <FieldError message={fieldError(errors, 'organization')} />
        </div>
        <div>
          <label className="label" htmlFor="experience_role">Role</label>
          <input
            id="experience_role"
            required
            maxLength={150}
            className="input"
            value={form.role}
            onChange={(e) => setForm((prev) => ({ ...prev, role: e.target.value }))}
          />
          <FieldError message={fieldError(errors, 'role')} />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="experience_start_date">Start date</label>
          <input
            id="experience_start_date"
            type="date"
            required
            max={TODAY}
            className="input"
            value={form.start_date}
            onChange={(e) => setForm((prev) => ({ ...prev, start_date: e.target.value }))}
          />
          <FieldError message={fieldError(errors, 'start_date')} />
        </div>
        <div>
          <label className="label" htmlFor="experience_end_date">End date (leave blank if ongoing)</label>
          <input
            id="experience_end_date"
            type="date"
            className="input"
            value={form.end_date}
            onChange={(e) => setForm((prev) => ({ ...prev, end_date: e.target.value }))}
          />
          <FieldError message={fieldError(errors, 'end_date')} />
        </div>
      </div>

      <div>
        <label className="label" htmlFor="experience_description">Description</label>
        <textarea
          id="experience_description"
          rows={3}
          className="input"
          value={form.description}
          onChange={(e) => setForm((prev) => ({ ...prev, description: e.target.value }))}
        />
        <FieldError message={fieldError(errors, 'description')} />
      </div>

      <div className="flex gap-3">
        <Button type="submit" isLoading={isSaving}>
          <Save className="h-4 w-4" />
          {submitLabel}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={isSaving}>
          <X className="h-4 w-4" />
          Cancel
        </Button>
      </div>
    </form>
  )
}
