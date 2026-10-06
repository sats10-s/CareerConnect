import { useEffect, useState } from 'react'
import { Spinner } from '@/components/ui/Spinner'
import { PageHeader } from '@/components/ui/PageHeader'
import { ProfileHeader } from '@/components/profile/ProfileHeader'
import { AcademicCard, AcademicHistoryCard, EducationCard, PersonalCard } from '@/components/profile/college/CollegeCards'
import { ReportCorrectionModal } from '@/components/profile/college/ReportCorrectionModal'
import type { CorrectionField } from '@/components/profile/college/collegeFormat'
import { MyProfileCard } from '@/components/profile/MyProfileCard'
import { ProjectsSection } from '@/components/profile/ProjectsSection'
import { CertificationsSection } from '@/components/profile/CertificationsSection'
import { ExperienceSection } from '@/components/profile/ExperienceSection'
import { ResumeSection } from '@/components/profile/ResumeSection'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { studentProfileService } from '@/services/studentProfileService'
import { formatDate } from '@/utils/dateFormat'
import type { StudentProfileData } from '@/types/studentProfile'

/**
 * The student's profile. College-record cards (Academic, Education,
 * Personal, history) are read-only with "Report a correction"; "My
 * Profile" and the resume are the student's to edit. Every mutation
 * returns the full fresh profile, which replaces this page's state.
 *
 * Desktop: header, Academic | Education, History | Personal, My Profile,
 * Resume. Phones stack header, Academic, Education, My Profile, Personal,
 * History, Resume (the `order-*` classes below).
 */
export function StudentProfilePage() {
  const [profile, setProfile] = useState<StudentProfileData | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [reportField, setReportField] = useState<CorrectionField | null>(null)

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

  const openCorrections = profile.correction_requests.filter((c) => c.status === 'open')

  return (
    <div className="space-y-6">
      <PageHeader title="Your profile" description="Your college record, plus the links and skills recruiters see." />

      <ProfileHeader profile={profile} />

      {openCorrections.length > 0 && (
        <div className="card flex flex-wrap items-center gap-2 px-5 py-3 text-sm text-ink-soft">
          <StatusBadge tone="info">Sent to placement office</StatusBadge>
          {openCorrections.map((c) => `${c.field_label} (${formatDate(c.created_at)})`).join(', ')}
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <AcademicCard profile={profile} onReportCorrection={setReportField} className="order-1" />
        <EducationCard profile={profile} onReportCorrection={setReportField} className="order-2" />
        <MyProfileCard profile={profile} onProfileChange={setProfile} className="order-3 lg:order-5 lg:col-span-2" />
        <PersonalCard profile={profile} onReportCorrection={setReportField} className="order-4" />
        <AcademicHistoryCard profile={profile} className="order-5 lg:order-3" />
        <div className="order-6 lg:col-span-2">
          <ResumeSection profile={profile} onProfileChange={setProfile} />
        </div>
      </div>

      <ProjectsSection profile={profile} onProfileChange={setProfile} />
      <CertificationsSection profile={profile} onProfileChange={setProfile} />
      <ExperienceSection profile={profile} onProfileChange={setProfile} />

      {reportField && <ReportCorrectionModal initialField={reportField} onClose={() => setReportField(null)} onProfileChange={setProfile} />}
    </div>
  )
}
