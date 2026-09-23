import { useEffect, useMemo, useState } from 'react'
import { WelcomeHeader } from '@/components/dashboard/WelcomeHeader'
import { MetricBand, type Metric } from '@/components/dashboard/MetricBand'
import { JourneyProgress, type JourneyStep } from '@/components/dashboard/JourneyProgress'
import { ApplicationActivity } from '@/components/dashboard/ApplicationActivity'
import { OpportunityList } from '@/components/dashboard/OpportunityList'
import { UpcomingInterview } from '@/components/dashboard/UpcomingInterview'
import { ProfileStrength } from '@/components/dashboard/ProfileStrength'
import { ActivityFeed } from '@/components/dashboard/ActivityFeed'
import { Spinner } from '@/components/ui/Spinner'
import { dashboardService } from '@/services/dashboardService'
import { useAuth } from '@/hooks/useAuth'
import type { StudentDashboardData } from '@/types/dashboard'

/**
 * Derives journey steps from the stats the backend already returns.
 * There's no dedicated "journey stage" field in the API - this is a
 * client-side presentation of existing counts, not fabricated data:
 * each step's state is a direct function of a real stat being > 0.
 */
function deriveJourneySteps(stats: StudentDashboardData['stats']): JourneyStep[] {
  const order = [
    { label: 'Registered', reached: true },
    { label: 'Applied', reached: stats.applications > 0 },
    { label: 'Shortlisted', reached: stats.shortlisted > 0 },
    { label: 'Interview', reached: stats.interviews > 0 },
    { label: 'Selected', reached: stats.selected > 0 },
  ]
  const currentIndex = order.reduce((last, step, i) => (step.reached ? i : last), 0)

  return order.map((step, i) => ({
    label: step.label,
    state: i < currentIndex ? 'complete' : i === currentIndex ? 'current' : 'upcoming',
  }))
}

export function StudentDashboardPage() {
  const { user } = useAuth()
  const [data, setData] = useState<StudentDashboardData | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    dashboardService.student().then(setData).finally(() => setIsLoading(false))
  }, [])

  const metrics = useMemo<Metric[]>(() => {
    if (!data) return []
    return [
      { label: 'Applications', value: data.stats.applications },
      { label: 'Shortlisted', value: data.stats.shortlisted },
      { label: 'Interviews', value: data.stats.interviews },
      { label: 'Selected', value: data.stats.selected },
    ]
  }, [data])

  if (isLoading) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (!data) {
    return <p className="text-sm text-ink-faint">Unable to load dashboard data right now.</p>
  }

  return (
    <div className="space-y-8">
      <WelcomeHeader name={user?.name ?? ''} department={user?.student?.department} batch={user?.student?.batch} />

      <MetricBand metrics={metrics} />

      <section>
        <h2 className="text-sm font-semibold text-ink">Your placement journey</h2>
        <div className="mt-4">
          <JourneyProgress steps={deriveJourneySteps(data.stats)} />
        </div>
      </section>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
        <div className="space-y-8 lg:col-span-2">
          <ApplicationActivity applications={data.recent_applications} />
          <OpportunityList />
        </div>

        <div className="space-y-6">
          <ProfileStrength percentage={data.stats.profile_completion} />
          <UpcomingInterview />
          <ActivityFeed notifications={data.notifications} />
        </div>
      </div>
    </div>
  )
}
