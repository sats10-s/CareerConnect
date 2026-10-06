import { CheckCircle2, Circle } from 'lucide-react'
import { Avatar } from '@/components/ui/Avatar'
import { StatusBadge } from '@/components/ui/StatusBadge'
import type { StudentProfileData } from '@/types/studentProfile'
import { entryLabel, programLabel, yearLabel } from './college/collegeFormat'

function Chip({ children }: { children: string }) {
  return <span className="inline-flex items-center rounded-full border border-border bg-sidebar px-2.5 py-0.5 text-xs font-medium text-ink-soft">{children}</span>
}

/**
 * Profile header: initials, name, JNTU No, and chips for department,
 * section, year and program · entry type. The completion meter (student
 * view) counts only what the student fills in - ProfileCompletionCalculator
 * on the backend is the single source of that number.
 */
export function ProfileHeader({ profile, showCompletion = true }: { profile: StudentProfileData; showCompletion?: boolean }) {
  const completion = Math.max(0, Math.min(100, profile.profile_completion))
  const isComplete = completion >= 100
  const program = [programLabel(profile.program), entryLabel(profile.entry_type)].filter(Boolean).join(' · ')
  const chips = [
    profile.department_code ?? profile.department,
    profile.section ? `Section ${profile.section}` : null,
    yearLabel(profile.year),
    program || null,
  ].filter((chip): chip is string => Boolean(chip))
  const missing = profile.profile_checklist.filter((item) => !item.done)

  return (
    <div className="card px-5 py-5 sm:px-6 sm:py-6">
      <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 items-start gap-4">
          <Avatar name={profile.name} size="lg" />
          <div className="min-w-0">
            <h2 className="truncate font-display text-xl font-bold text-ink">{profile.name}</h2>
            <p className="mt-0.5 font-mono text-sm text-ink-soft">{profile.roll_number}</p>
            {chips.length > 0 && (
              <div className="mt-2.5 flex flex-wrap gap-1.5">
                {chips.map((chip) => <Chip key={chip}>{chip}</Chip>)}
              </div>
            )}
          </div>
        </div>

        {showCompletion && (
          <div className="lg:w-72">
            <div className="flex items-baseline justify-between">
              <span className="text-xs font-medium uppercase tracking-wide text-ink-faint">Profile completion</span>
              {isComplete && <StatusBadge tone="gold">Complete</StatusBadge>}
            </div>
            <div className="mt-2 flex items-center gap-3">
              <div className="h-2 flex-1 overflow-hidden rounded-full bg-sidebar" role="progressbar" aria-label="Profile completion" aria-valuenow={completion} aria-valuemin={0} aria-valuemax={100}>
                <div className={`h-full rounded-full transition-[width] ${isComplete ? 'bg-gold' : 'bg-primary'}`} style={{ width: `${completion}%` }} />
              </div>
              <span className="text-sm font-semibold tabular-nums text-ink">{completion}%</span>
            </div>
            {missing.length > 0 && (
              <ul className="mt-3 grid grid-cols-1 gap-1 text-xs sm:grid-cols-2 lg:grid-cols-1">
                {profile.profile_checklist.map((item) => (
                  <li key={item.key} className={`flex items-center gap-1.5 ${item.done ? 'text-ink-faint' : 'text-ink-soft'}`}>
                    {item.done ? <CheckCircle2 className="h-3.5 w-3.5 text-success" /> : <Circle className="h-3.5 w-3.5" />}
                    {item.label}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
