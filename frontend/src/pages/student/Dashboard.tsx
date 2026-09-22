import { useEffect, useState } from 'react'
import { Briefcase, CheckCircle2, ClipboardList, MessagesSquare, UserCircle2 } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { Card, CardHeader } from '@/components/ui/Card'
import { Badge, toneForStatus } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { dashboardService } from '@/services/dashboardService'
import { useAuth } from '@/hooks/useAuth'
import type { StudentDashboardData } from '@/types/dashboard'

export function StudentDashboardPage() {
  const { user } = useAuth()
  const [data, setData] = useState<StudentDashboardData | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    dashboardService.student().then(setData).finally(() => setIsLoading(false))
  }, [])

  if (isLoading) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (!data) {
    return <p className="text-sm text-gray-500">Unable to load dashboard data right now.</p>
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-gray-900">Welcome back, {user?.name?.split(' ')[0]} 👋</h2>
        <p className="mt-1 text-sm text-gray-500">Here's where your placement journey stands today.</p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <StatCard label="Applications" value={data.stats.applications} icon={ClipboardList} tone="navy" />
        <StatCard label="Shortlisted" value={data.stats.shortlisted} icon={Briefcase} tone="amber" />
        <StatCard label="Interviews" value={data.stats.interviews} icon={MessagesSquare} tone="blue" />
        <StatCard label="Selected" value={data.stats.selected} icon={CheckCircle2} tone="green" />
        <StatCard label="Profile Completion" value={data.stats.profile_completion} suffix="%" icon={UserCircle2} tone="navy" />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Recent applications" subtitle="Your latest job & internship applications" />
          <div className="divide-y divide-gray-100">
            {data.recent_applications.length === 0 && (
              <p className="px-5 py-8 text-center text-sm text-gray-500">No applications yet — browse Jobs or Internships to get started.</p>
            )}
            {data.recent_applications.map((app) => (
              <div key={app.id} className="flex items-center justify-between gap-4 px-5 py-3.5">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-gray-900">{app.title ?? 'Untitled opportunity'}</p>
                  <p className="truncate text-xs text-gray-500">{app.company} · {app.type === 'internship' ? 'Internship' : 'Job'}</p>
                </div>
                <Badge tone={toneForStatus(app.status)}>{app.status}</Badge>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <CardHeader title="Notifications" />
          <div className="divide-y divide-gray-100">
            {data.notifications.map((note) => (
              <div key={note.id} className="px-5 py-3.5 text-sm text-gray-700">{note.message}</div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  )
}
