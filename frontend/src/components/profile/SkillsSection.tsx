import { useState, type FormEvent } from 'react'
import { Plus, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { studentProfileService } from '@/services/studentProfileService'
import type { StudentProfileData } from '@/types/studentProfile'
import { describeFailure, ErrorBanner } from './shared'

/**
 * Individual skills, each its own record (student_skills table) - a
 * chip per skill with its own remove control, added one at a time.
 * Deliberately NOT a single comma-separated text field, per the
 * module spec: "Add/remove individual skills."
 */
export function SkillsSection({
  profile,
  onProfileChange,
}: {
  profile: StudentProfileData
  onProfileChange: (profile: StudentProfileData) => void
}) {
  const [draft, setDraft] = useState('')
  const [isAdding, setIsAdding] = useState(false)
  const [removingId, setRemovingId] = useState<number | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  async function handleAdd(event: FormEvent) {
    event.preventDefault()
    const skillName = draft.trim()
    if (!skillName) return
    setIsAdding(true)
    setFormError(null)
    try {
      const updated = await studentProfileService.addSkill({ skill_name: skillName })
      onProfileChange(updated)
      setDraft('')
    } catch (err) {
      const failure = describeFailure(err, 'Unable to add this skill. Please try again.')
      setFormError(failure.errors.skill_name?.[0] ?? failure.message)
    } finally {
      setIsAdding(false)
    }
  }

  async function handleRemove(skillId: number) {
    setRemovingId(skillId)
    setFormError(null)
    try {
      const updated = await studentProfileService.deleteSkill(skillId)
      onProfileChange(updated)
    } catch {
      setFormError('Unable to remove this skill. Please try again.')
    } finally {
      setRemovingId(null)
    }
  }

  return (
    <Card>
      <CardHeader title="Skills" subtitle="Add the technologies and tools you're proficient with" />

      <div className="space-y-4 px-5 py-5">
        {formError && <ErrorBanner message={formError} />}

        {profile.skills.length === 0 ? (
          <p className="text-sm text-ink-faint">No skills added yet.</p>
        ) : (
          <ul className="flex flex-wrap gap-2">
            {profile.skills.map((skill) => (
              <li
                key={skill.id}
                className="inline-flex items-center gap-1.5 rounded-full bg-indigo-tint py-1 pl-3 pr-1.5 text-sm font-medium text-indigo"
              >
                {skill.skill_name}
                <button
                  type="button"
                  aria-label={`Remove ${skill.skill_name}`}
                  disabled={removingId === skill.id}
                  onClick={() => handleRemove(skill.id)}
                  className="rounded-full p-0.5 text-indigo/70 transition-colors hover:bg-indigo hover:text-[color:var(--color-primary-ink)] disabled:opacity-50"
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              </li>
            ))}
          </ul>
        )}

        <form onSubmit={handleAdd} className="flex gap-2">
          <input
            aria-label="New skill"
            className="input"
            placeholder="e.g. React, Python, Figma"
            value={draft}
            maxLength={100}
            onChange={(e) => setDraft(e.target.value)}
          />
          <Button type="submit" variant="secondary" isLoading={isAdding} disabled={!draft.trim()}>
            <Plus className="h-4 w-4" />
            Add
          </Button>
        </form>
      </div>
    </Card>
  )
}
