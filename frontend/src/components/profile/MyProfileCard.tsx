import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { ExternalLink, Pencil, Plus, Save, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { studentProfileService } from '@/services/studentProfileService'
import type { StudentProfileData } from '@/types/studentProfile'
import { describeFailure, ErrorBanner, fieldError } from './shared'

const MAX_SKILLS = 30
const LINKEDIN = /^https:\/\/(www\.)?linkedin\.com\/in\/[^/\s?#]+\/?(\?\S*)?$/i

interface FormState {
  linkedin_url: string
  portfolio_url: string
  github_url: string
  personal_email: string
  address: string
}

const toForm = (p: StudentProfileData): FormState => ({
  linkedin_url: p.linkedin_url ?? '',
  portfolio_url: p.portfolio_url ?? '',
  github_url: p.github_url ?? '',
  personal_email: p.personal_email ?? '',
  address: p.address ?? '',
})

/**
 * The student's own part of the profile: LinkedIn, portfolio, GitHub, skill
 * tags, personal email and address. Editable by the student (pass
 * onProfileChange); read-only for staff.
 */
export function MyProfileCard({ profile, onProfileChange, className = '' }: { profile: StudentProfileData; onProfileChange?: (p: StudentProfileData) => void; className?: string }) {
  const editable = Boolean(onProfileChange)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState<FormState>(() => toForm(profile))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [banner, setBanner] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function start() {
    setForm(toForm(profile))
    setErrors({})
    setBanner(null)
    setEditing(true)
  }

  async function save(event: FormEvent) {
    event.preventDefault()
    const local: Record<string, string[]> = {}
    if (form.linkedin_url.trim() && !LINKEDIN.test(form.linkedin_url.trim())) local.linkedin_url = ['Use your LinkedIn profile link, like https://www.linkedin.com/in/your-name.']
    for (const key of ['portfolio_url', 'github_url'] as const) {
      if (form[key].trim() && !/^https:\/\/\S+\.\S+/.test(form[key].trim())) local[key] = ['Use a full https:// link.']
    }
    const original = toForm(profile)
    const email = form.personal_email.trim()
    if ((email || original.personal_email) && !/^\S+@\S+\.\S+$/.test(email)) local.personal_email = ['Enter a valid personal email - password reset links go there.']
    setErrors(local)
    if (Object.keys(local).length) return

    // Send only what changed.
    const payload: Partial<Record<keyof FormState, string | null>> = {}
    for (const key of Object.keys(form) as (keyof FormState)[]) {
      if (form[key].trim() !== original[key]) payload[key] = form[key].trim() || null
    }
    if (Object.keys(payload).length === 0) return setEditing(false)

    setSaving(true)
    setBanner(null)
    try {
      onProfileChange?.(await studentProfileService.updateProfile(payload))
      setEditing(false)
    } catch (err) {
      const failure = describeFailure(err, 'Unable to save your profile. Please try again.')
      setErrors(failure.errors)
      setBanner(failure.message)
    } finally {
      setSaving(false)
    }
  }

  const set = (key: keyof FormState) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  return (
    <section className={`card ${className}`}>
      <header className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
        <div>
          <h3 className="text-sm font-semibold text-ink">My Profile</h3>
          <p className="mt-0.5 text-xs text-ink-faint">{editable ? 'Your links, skills and contact details - recruiters see these.' : "Added by the student."}</p>
        </div>
        {editable && !editing && (
          <Button type="button" variant="secondary" onClick={start}>
            <Pencil className="h-4 w-4" /> Edit
          </Button>
        )}
      </header>

      {editing ? (
        <form onSubmit={save} className="space-y-4 px-5 py-5" noValidate>
          {banner && <ErrorBanner message={banner} />}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="mp-linkedin">LinkedIn</label>
              <Input id="mp-linkedin" placeholder="https://www.linkedin.com/in/your-name" value={form.linkedin_url} onChange={set('linkedin_url')} error={fieldError(errors, 'linkedin_url')} />
            </div>
            <div>
              <label className="label" htmlFor="mp-portfolio">Portfolio</label>
              <Input id="mp-portfolio" placeholder="https://your-site.dev" value={form.portfolio_url} onChange={set('portfolio_url')} error={fieldError(errors, 'portfolio_url')} />
            </div>
            <div>
              <label className="label" htmlFor="mp-github">GitHub</label>
              <Input id="mp-github" placeholder="https://github.com/you" value={form.github_url} onChange={set('github_url')} error={fieldError(errors, 'github_url')} />
            </div>
            <div>
              <label className="label" htmlFor="mp-email">Personal email</label>
              <Input id="mp-email" type="email" value={form.personal_email} onChange={set('personal_email')} error={fieldError(errors, 'personal_email')} />
            </div>
          </div>
          <div>
            <label className="label" htmlFor="mp-address">Address</label>
            <Textarea id="mp-address" rows={2} maxLength={500} value={form.address} onChange={set('address')} error={fieldError(errors, 'address')} />
          </div>
          <div className="flex gap-2">
            <Button type="submit" isLoading={saving}><Save className="h-4 w-4" /> Save</Button>
            <Button type="button" variant="secondary" onClick={() => setEditing(false)} disabled={saving}><X className="h-4 w-4" /> Cancel</Button>
          </div>
        </form>
      ) : (
        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-2">
          <Item label="LinkedIn"><LinkValue url={profile.linkedin_url} empty={editable ? 'Add your LinkedIn profile' : 'Not added'} /></Item>
          <Item label="Portfolio"><LinkValue url={profile.portfolio_url} empty={editable ? 'Add a portfolio link' : 'Not added'} /></Item>
          <Item label="GitHub"><LinkValue url={profile.github_url} empty="Not added" /></Item>
          <Item label="Personal email">{profile.personal_email ?? <span className="text-ink-faint">Not added</span>}</Item>
          <Item label="Address" wide>{profile.address ? <span className="whitespace-pre-line">{profile.address}</span> : <span className="text-ink-faint">Not added</span>}</Item>
        </dl>
      )}

      <div className="border-t border-border px-5 py-5">
        <SkillTags profile={profile} onProfileChange={onProfileChange} />
      </div>
    </section>
  )
}

function Item({ label, children, wide }: { label: string; children: ReactNode; wide?: boolean }) {
  return (
    <div className={`min-w-0 ${wide ? 'sm:col-span-2' : ''}`}>
      <dt className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</dt>
      <dd className="mt-1 break-words text-sm font-medium text-ink">{children}</dd>
    </div>
  )
}

function LinkValue({ url, empty }: { url: string | null; empty: string }) {
  if (!url) return <span className="text-ink-faint">{empty}</span>
  return (
    <a href={url} target="_blank" rel="noopener noreferrer" className="inline-flex max-w-full items-center gap-1 text-primary hover:underline">
      <span className="truncate">{url.replace(/^https?:\/\/(www\.)?/, '')}</span>
      <ExternalLink className="h-3.5 w-3.5 shrink-0" aria-label="opens in a new tab" />
    </a>
  )
}

/** Skill tags with suggestions. Duplicates are refused case-insensitively by the server too. */
function SkillTags({ profile, onProfileChange }: { profile: StudentProfileData; onProfileChange?: (p: StudentProfileData) => void }) {
  const editable = Boolean(onProfileChange)
  const [draft, setDraft] = useState('')
  const [suggestions, setSuggestions] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const full = profile.skills.length >= MAX_SKILLS

  useEffect(() => {
    if (!editable) return
    const query = draft.trim()
    const timer = window.setTimeout(() => {
      studentProfileService.suggestSkills(query).then(setSuggestions).catch(() => setSuggestions([]))
    }, 200)
    return () => window.clearTimeout(timer)
  }, [draft, editable])

  async function add(event: FormEvent) {
    event.preventDefault()
    const name = draft.trim().replace(/\s+/g, ' ')
    if (!name) return
    if (profile.skills.some((s) => s.skill_name.toLowerCase() === name.toLowerCase())) return setError('This skill is already on your profile.')
    setBusy(true)
    setError(null)
    try {
      onProfileChange?.(await studentProfileService.addSkill({ skill_name: name }))
      setDraft('')
    } catch (err) {
      const failure = describeFailure(err, 'Unable to add this skill.')
      setError(failure.errors.skill_name?.[0] ?? failure.message)
    } finally {
      setBusy(false)
    }
  }

  async function remove(id: number) {
    setError(null)
    try {
      onProfileChange?.(await studentProfileService.deleteSkill(id))
    } catch {
      setError('Unable to remove this skill.')
    }
  }

  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">
        Skills <span className="normal-case tracking-normal">· {profile.skills.length}/{MAX_SKILLS}</span>
      </p>
      {profile.skills.length === 0 ? (
        <p className="mt-2 text-sm text-ink-faint">{editable ? 'Add at least 3 skills so recruiters can find you.' : 'No skills added.'}</p>
      ) : (
        <ul className="mt-2 flex flex-wrap gap-2">
          {profile.skills.map((skill) => (
            <li key={skill.id} className="inline-flex items-center gap-1 rounded-full bg-primary-tint py-1 pl-3 pr-1.5 text-sm font-medium text-primary">
              {skill.skill_name}
              {editable ? (
                <button type="button" aria-label={`Remove ${skill.skill_name}`} onClick={() => remove(skill.id)} className="rounded-full p-0.5 hover:bg-primary hover:text-[color:var(--color-primary-ink)]">
                  <X className="h-3.5 w-3.5" />
                </button>
              ) : <span className="pr-1.5" />}
            </li>
          ))}
        </ul>
      )}
      {editable && (
        <form onSubmit={add} className="mt-3 flex gap-2">
          <input
            aria-label="Add a skill"
            className="input"
            list="skill-suggestions"
            placeholder={full ? 'Skill limit reached' : 'e.g. React, Python, Figma'}
            maxLength={40}
            disabled={full}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
          />
          <datalist id="skill-suggestions">
            {suggestions.map((s) => <option key={s} value={s} />)}
          </datalist>
          <Button type="submit" variant="secondary" isLoading={busy} disabled={!draft.trim() || full}>
            <Plus className="h-4 w-4" /> Add
          </Button>
        </form>
      )}
      {error && <p role="alert" className="mt-2 text-xs text-error">{error}</p>}
    </div>
  )
}
