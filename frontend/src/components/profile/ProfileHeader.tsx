import { Avatar } from '@/components/ui/Avatar'
import { StatusBadge } from '@/components/ui/StatusBadge'
import type { StudentProfileData } from '@/types/studentProfile'

/**
 * The Profile page's header: identity (avatar/name/college email/roll
 * number) plus the single, backend-calculated completion percentage -
 * this is the ONLY place that number is rendered on this page, so
 * there is never a second, drifted "estimate" anywhere else in the
 * module (see ProfileCompletionCalculator on the backend).
 */
export function ProfileHeader({ profile }: { profile: StudentProfileData }) {
  const completion = Math.max(0, Math.min(100, profile.profile_completion))
  const isComplete = completion >= 100

  return (
    <div className="card px-5 py-5 sm:px-6 sm:py-6">
      <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-4">
          <Avatar name={profile.name} size="lg" />
          <div>
            <h2 className="text-lg font-bold text-ink">{profile.name}</h2>
            <p className="mt-0.5 text-sm text-ink-faint">
              {profile.college_email}
              {profile.roll_number && <span> · {profile.roll_number}</span>}
            </p>
          </div>
        </div>

        <div className="sm:w-64">
          <div className="flex items-baseline justify-between">
            <span className="text-xs font-medium uppercase tracking-wide text-ink-faint">Profile completion</span>
            {isComplete && <StatusBadge tone="gold">Complete</StatusBadge>}
          </div>
          <div className="mt-2 flex items-center gap-3">
            <div
              className="h-2 flex-1 overflow-hidden rounded-full bg-sidebar"
              role="progressbar"
              aria-valuenow={completion}
              aria-valuemin={0}
              aria-valuemax={100}
            >
              <div
                className={`h-full rounded-full transition-[width] ${isComplete ? 'bg-gold' : 'bg-indigo'}`}
                style={{ width: `${completion}%` }}
              />
            </div>
            <span className="text-sm font-semibold tabular-nums text-ink">{completion}%</span>
          </div>
        </div>
      </div>
    </div>
  )
}
