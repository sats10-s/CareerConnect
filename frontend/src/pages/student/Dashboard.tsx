import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { WelcomeHeader } from '@/components/dashboard/WelcomeHeader'
import { MetricBand, type Metric } from '@/components/dashboard/MetricBand'
import { JourneyProgress, type JourneyStep } from '@/components/dashboard/JourneyProgress'
import { ApplicationActivity } from '@/components/dashboard/ApplicationActivity'
import { OpportunityList } from '@/components/opportunities/OpportunityList'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { EmptyState } from '@/components/ui/EmptyState'
import { Briefcase } from 'lucide-react'
import { UpcomingInterview } from '@/components/dashboard/UpcomingInterview'
import { ProfileStrength } from '@/components/dashboard/ProfileStrength'
import { ActivityFeed } from '@/components/dashboard/ActivityFeed'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { parseApiError } from '@/utils/apiError'
import { dashboardService } from '@/services/dashboardService'
import { opportunityService } from '@/services/opportunityService'
import { interviewService } from '@/services/interviewService'
import { notificationService } from '@/services/notificationService'
import type { NotificationItem } from '@/types/notification'
import { useAppliedOpportunities } from '@/hooks/useAppliedOpportunities'
import { useAuth } from '@/hooks/useAuth'
import type { StudentDashboardData } from '@/types/dashboard'
import type { OpportunityListItem } from '@/types/opportunity'
import type { Interview } from '@/components/dashboard/UpcomingInterview'

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
  const navigate = useNavigate()
  const [data, setData] = useState<StudentDashboardData | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [attempt, setAttempt] = useState(0)
  const [opportunities, setOpportunities] = useState<OpportunityListItem[]>([])
  const { statusFor: appliedStatusFor } = useAppliedOpportunities()
  const [interviews, setInterviews] = useState<Interview[]>([])
  const [activity, setActivity] = useState<NotificationItem[] | null>([])

  useEffect(() => {
    setIsLoading(true)
    setLoadError(null)
    dashboardService
      .student()
      .then(setData)
      .catch((err: unknown) => setLoadError(parseApiError(err, 'Unable to load your dashboard.').message))
      .finally(() => setIsLoading(false))

    // Neither of these has its own dashboard field - both are real
    // endpoints (Jobs/Internships browsing, Student interviews) fetched
    // directly rather than left as permanent empty states.
    opportunityService
      .list({ per_page: 3 })
      .then((result) => setOpportunities(result.items))
      .catch(() => setOpportunities([]))

    interviewService
      .list({ when: 'upcoming', per_page: 1 })
      .then((result) =>
        setInterviews(
          result.items.map((item) => ({
            id: item.id,
            company: item.opportunity?.company?.name ?? 'Unknown company',
            role: item.title ?? item.opportunity?.title ?? `Round ${item.round}`,
            scheduledFor: item.scheduled_at
              ? new Date(item.scheduled_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
              : 'Time to be confirmed',
          })),
        ),
      )
      .catch(() => setInterviews([]))

    notificationService
      .list('STUDENT', { per_page: 4 })
      .then((result) => setActivity(result.items))
      .catch(() => setActivity(null))
  }, [attempt])

  const metrics = useMemo<Metric[]>(() => {
    if (!data) return []
    return [
      { label: 'Applications', value: data.stats.applications },
      { label: 'Shortlisted', value: data.stats.shortlisted },
      { label: 'Interviews', value: data.stats.interviews },
      { label: 'Selected', value: data.stats.selected },
    ]
  }, [data])

  if (isLoading && !data) return <DashboardLoading />
  if (loadError || !data) return <DashboardError message={loadError} onRetry={() => setAttempt((n) => n + 1)} />

  return (
    <div className="animate-fade-in space-y-8">
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

          <section>
            <SectionHeader title="Open opportunities" subtitle="Recently posted jobs and internships" />
            <div className="mt-3">
              {opportunities.length === 0 ? (
                <div className="card">
                  <EmptyState icon={Briefcase} title="No open opportunities to show yet" />
                </div>
              ) : (
                <OpportunityList
                  items={opportunities}
                  appliedStatusFor={(item) => appliedStatusFor(item.type, item.id)}
                  // Dashboard is a "recently posted" preview, not the
                  // full browse-and-apply flow (no detail page or
                  // Apply confirmation lives here) - selecting a card
                  // takes the student to that opportunity's real
                  // detail page, where they can view it in full and
                  // apply.
                  onSelect={(item) => navigate(`/student/${item.type === 'internship' ? 'internships' : 'jobs'}/${item.id}`)}
                />
              )}
            </div>
          </section>
        </div>

        <div className="space-y-6">
          <ProfileStrength percentage={data.stats.profile_completion} />
          <UpcomingInterview interviews={interviews} />
          <ActivityFeed notifications={activity} />
        </div>
      </div>
    </div>
  )
}
