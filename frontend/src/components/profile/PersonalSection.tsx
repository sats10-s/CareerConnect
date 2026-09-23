import { useState, type FormEvent } from 'react'
import { Lock, Pencil, Save, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { studentProfileService } from '@/services/studentProfileService'
import type { StudentProfileData } from '@/types/studentProfile'
import { describeFailure, ErrorBanner, FieldError, fieldError } from './shared'

interface FormState {
  personal_email: string
  phone: string
}

function toForm(profile: StudentProfileData): FormState {
  return {
    personal_email: profile.personal_email ?? '',
    phone: profile.phone ?? '',
  }
}

/**
 * Personal information - full name and college email are shown but
 * read-only (the college email is the immutable login identity; see
 * UpdateProfileRequest's `prohibited` rule and this module's spec
 * section 2/5 - never render an edit control for it and never trust
 * a client-side read-only flag as the actual security boundary, the
 * backend rejects any attempt regardless).
 */
export function PersonalSection({
  profile,
  onProfileChange,
}: {
  profile: StudentProfileData
  onProfileChange: (profile: StudentProfileData) => void
}) {
  const [isEditing, setIsEditing] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [form, setForm] = useState<FormState>(() => toForm(profile))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

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
        personal_email: form.personal_email === '' ? null : form.personal_email,
        phone: form.phone === '' ? null : form.phone,
      })
      const updated = await studentProfileService.updateProfile(payload)
      onProfileChange(updated)
      setIsEditing(false)
    } catch (err) {
      const failure = describeFailure(err, 'Unable to update your personal information. Please try again.')
      setErrors(failure.errors)
      setFormError(failure.message)
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Personal information"
        subtitle="Your identity and contact details"
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
        <div className="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-2">
          <ReadOnlyField label="Full name" value={profile.name} />
          <ReadOnlyField label="College email" value={profile.college_email} locked />
          <ReadOnlyField label="Personal email" value={profile.personal_email ?? '—'} />
          <ReadOnlyField label="Phone" value={profile.phone ?? '—'} />
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4 px-5 py-5">
          {formError && <ErrorBanner message={formError} />}

          <div>
            <label className="label" htmlFor="college_email">College email</label>
            <div className="flex items-center gap-2">
              <input id="college_email" className="input" value={profile.college_email} disabled readOnly />
              <Lock className="h-4 w-4 shrink-0 text-ink-faint" aria-hidden="true" />
            </div>
            <p className="mt-1 text-xs text-ink-faint">Your college email is fixed and cannot be changed here.</p>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="personal_email">Personal email</label>
              <input
                id="personal_email"
                type="email"
                className="input"
                placeholder="you@example.com"
                value={form.personal_email}
                onChange={(e) => setForm((prev) => ({ ...prev, personal_email: e.target.value }))}
              />
              <FieldError message={fieldError(errors, 'personal_email')} />
            </div>
            <div>
              <label className="label" htmlFor="phone">Phone</label>
              <input
                id="phone"
                className="input"
                placeholder="+91 98765 43210"
                value={form.phone}
                onChange={(e) => setForm((prev) => ({ ...prev, phone: e.target.value }))}
              />
              <FieldError message={fieldError(errors, 'phone')} />
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

function ReadOnlyField({ label, value, locked }: { label: string; value: string; locked?: boolean }) {
  return (
    <div>
      <p className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-ink-faint">
        {label}
        {locked && <Lock className="h-3 w-3" aria-hidden="true" />}
      </p>
      <p className="mt-1 text-sm font-medium text-ink">{value}</p>
    </div>
  )
}
