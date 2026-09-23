import { useState, type FormEvent } from 'react'
import { ExternalLink, Github, Globe, Linkedin, Pencil, Save, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { studentProfileService } from '@/services/studentProfileService'
import type { StudentProfileData } from '@/types/studentProfile'
import { describeFailure, ErrorBanner, FieldError, fieldError } from './shared'

interface FormState {
  linkedin_url: string
  github_url: string
  portfolio_url: string
}

function toForm(profile: StudentProfileData): FormState {
  return {
    linkedin_url: profile.linkedin_url ?? '',
    github_url: profile.github_url ?? '',
    portfolio_url: profile.portfolio_url ?? '',
  }
}

const LINK_ROWS: { key: keyof FormState; label: string; icon: typeof Linkedin; placeholder: string }[] = [
  { key: 'linkedin_url', label: 'LinkedIn', icon: Linkedin, placeholder: 'https://linkedin.com/in/…' },
  { key: 'github_url', label: 'GitHub', icon: Github, placeholder: 'https://github.com/…' },
  { key: 'portfolio_url', label: 'Portfolio', icon: Globe, placeholder: 'https://…' },
]

/** LinkedIn/GitHub/portfolio - all three together count toward profile completion; a partial set earns none of that weight (see ProfileCompletionCalculator::linksComplete). */
export function LinksSection({
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
        linkedin_url: form.linkedin_url === '' ? null : form.linkedin_url,
        github_url: form.github_url === '' ? null : form.github_url,
        portfolio_url: form.portfolio_url === '' ? null : form.portfolio_url,
      })
      const updated = await studentProfileService.updateProfile(payload)
      onProfileChange(updated)
      setIsEditing(false)
    } catch (err) {
      const failure = describeFailure(err, 'Unable to update your links. Please try again.')
      setErrors(failure.errors)
      setFormError(failure.message)
    } finally {
      setIsSaving(false)
    }
  }

  const hasAnyLink = Boolean(profile.linkedin_url || profile.github_url || profile.portfolio_url)

  return (
    <Card>
      <CardHeader
        title="Professional links"
        subtitle="LinkedIn, GitHub, and your portfolio"
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
        <div className="px-5 py-5">
          {!hasAnyLink ? (
            <p className="text-sm text-ink-faint">No links added yet.</p>
          ) : (
            <ul className="space-y-2.5">
              {LINK_ROWS.map(({ key, label, icon: Icon }) => {
                const value = profile[key]
                if (!value) return null
                return (
                  <li key={key} className="flex items-center gap-2.5 text-sm">
                    <Icon className="h-4 w-4 shrink-0 text-ink-faint" aria-hidden="true" />
                    <span className="w-20 shrink-0 text-ink-faint">{label}</span>
                    <a
                      href={value}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="flex items-center gap-1 truncate font-medium text-indigo hover:underline"
                    >
                      <span className="truncate">{value}</span>
                      <ExternalLink className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    </a>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4 px-5 py-5">
          {formError && <ErrorBanner message={formError} />}

          {LINK_ROWS.map(({ key, label, placeholder }) => (
            <div key={key}>
              <label className="label" htmlFor={key}>{label}</label>
              <input
                id={key}
                type="url"
                className="input"
                placeholder={placeholder}
                value={form[key]}
                onChange={(e) => setForm((prev) => ({ ...prev, [key]: e.target.value }))}
              />
              <FieldError message={fieldError(errors, key)} />
            </div>
          ))}

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
