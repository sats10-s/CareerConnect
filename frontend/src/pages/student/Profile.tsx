import { useEffect, useState } from 'react'
import { Spinner } from '@/components/ui/Spinner'
import { ProfileHeader } from '@/components/profile/ProfileHeader'
import { PersonalSection } from '@/components/profile/PersonalSection'
import { AcademicSection } from '@/components/profile/AcademicSection'
import { SkillsSection } from '@/components/profile/SkillsSection'
import { ProjectsSection } from '@/components/profile/ProjectsSection'
import { CertificationsSection } from '@/components/profile/CertificationsSection'
import { ExperienceSection } from '@/components/profile/ExperienceSection'
import { LinksSection } from '@/components/profile/LinksSection'
import { ResumeSection } from '@/components/profile/ResumeSection'
import { studentProfileService } from '@/services/studentProfileService'
import type { StudentProfileData } from '@/types/studentProfile'

/**
 * The complete Student Profile module screen. Every section is fed
 * from a single `profile` state, loaded once here from the real API
 * (never mock data) - every section mutation returns the full, fresh
 * profile (see ReturnsFreshProfile on the backend), so `onProfileChange`
 * just replaces this state wholesale rather than merging partial data.
 */
export function StudentProfilePage() {
  const [profile, setProfile] = useState<StudentProfileData | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  useEffect(() => {
    loadProfile()
  }, [])

  function loadProfile() {
    setIsLoading(true)
    setLoadError(null)
    studentProfileService
      .getProfile()
      .then(setProfile)
      .catch(() => setLoadError('Unable to load your profile right now. Please try again.'))
      .finally(() => setIsLoading(false))
  }

  if (isLoading) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (loadError || !profile) {
    return (
      <div className="flex min-h-[50vh] flex-col items-center justify-center gap-3 text-center">
        <p className="text-sm text-ink-faint">{loadError ?? 'Your profile is not available right now.'}</p>
        <button type="button" className="btn-secondary" onClick={loadProfile}>
          Try again
        </button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-ink">Your profile</h2>
        <p className="mt-1 text-sm text-ink-faint">Keep this up to date so recruiters see your full picture.</p>
      </div>

      <ProfileHeader profile={profile} />
      <PersonalSection profile={profile} onProfileChange={setProfile} />
      <AcademicSection profile={profile} onProfileChange={setProfile} />
      <SkillsSection profile={profile} onProfileChange={setProfile} />
      <ProjectsSection profile={profile} onProfileChange={setProfile} />
      <CertificationsSection profile={profile} onProfileChange={setProfile} />
      <ExperienceSection profile={profile} onProfileChange={setProfile} />
      <LinksSection profile={profile} onProfileChange={setProfile} />
      <ResumeSection profile={profile} onProfileChange={setProfile} />
    </div>
  )
}
