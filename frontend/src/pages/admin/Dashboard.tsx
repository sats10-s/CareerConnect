import { useEffect, useState } from 'react'
import { Briefcase, Building2, CheckCircle2, FileText, GraduationCap, Users } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { Card, CardHeader } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { dashboardService } from '@/services/dashboardService'
import type { AdminDashboardData } from '@/types/dashboard'

export function AdminDashboardPage() {
  const [data, setData] = useState<AdminDashboardData | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    dashboardService.admin().then(setData).finally(() => setIsLoading(false))
  }, [])

  if (isLoading) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (!data) return <p className="text-sm text-muted">Unable to load dashboard data right now.</p>

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-ink">Placement overview</h2>
        <p className="mt-1 text-sm text-muted">College-wide placement activity at a glance.</p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard label="Students" value={data.stats.total_students} icon={Users} tone="navy" />
        <StatCard label="Companies" value={data.stats.companies} icon={Building2} tone="blue" />
        <StatCard label="Active Jobs" value={data.stats.active_jobs} icon={Briefcase} tone="amber" />
        <StatCard label="Active Internships" value={data.stats.active_internships} icon={GraduationCap} tone="blue" />
        <StatCard label="Applications" value={data.stats.applications} icon={FileText} tone="navy" />
        <StatCard label="Selected" value={data.stats.selected_students} icon={CheckCircle2} tone="green" />
      </div>

      <Card>
        <CardHeader title="Recent activity" subtitle="Latest applications and companies onboarded" />
        <div className="divide-y divide-gray-100">
          {data.recent_activity.length === 0 && (
            <p className="px-5 py-8 text-center text-sm text-muted">No recent activity.</p>
          )}
          {data.recent_activity.map((item, idx) => (
            <div key={idx} className="px-5 py-3.5 text-sm text-ink-soft">{item.description}</div>
          ))}
        </div>
      </Card>
    </div>
  )
}
