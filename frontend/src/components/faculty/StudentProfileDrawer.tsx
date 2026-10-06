import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { ProfileHeader } from '@/components/profile/ProfileHeader'
import { MyProfileCard } from '@/components/profile/MyProfileCard'
import { AcademicCard, AcademicHistoryCard, EducationCard } from '@/components/profile/college/CollegeCards'
import { useApiResource } from '@/hooks/useApiResource'
import { facultyService } from '@/services/facultyService'

/** GET /faculty/students/{id}: an assigned student's academic, education and "My Profile" cards - not the personal record. */
export function StudentProfileDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => facultyService.studentProfile(id as number), id)

  return (
    <Drawer open={id !== null} onClose={onClose} title={data?.name ?? 'Student profile'} width="lg">
      {isLoading && !data ? (
        <div className="space-y-4" aria-busy="true">
          <LoadingSkeleton className="h-20 w-full" />
          <LoadingSkeleton className="h-40 w-full" />
        </div>
      ) : error ? (
        <ErrorState description={error} onRetry={reload} />
      ) : data ? (
        <div className="space-y-4">
          <ProfileHeader profile={data} showCompletion={false} />
          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <AcademicCard profile={data} />
            <EducationCard profile={data} />
          </div>
          <MyProfileCard profile={data} />
          <AcademicHistoryCard profile={data} />
        </div>
      ) : null}
    </Drawer>
  )
}
