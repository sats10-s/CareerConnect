import { Briefcase, Building2, CheckCircle2, FileText, GraduationCap, History, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import { StatCard } from '@/components/ui/StatCard'
import { PageHeader } from '@/components/ui/PageHeader'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { EmptyState } from '@/components/ui/EmptyState'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { useApiResource } from '@/hooks/useApiResource'
import { dashboardService } from '@/services/dashboardService'
import { formatRelativeTime } from '@/utils/formatRelativeTime'

export function AdminDashboardPage() {
  const { data, isLoading, error, reload } = useApiResource(() => dashboardService.admin(), 'admin-dashboard')

  if (isLoading && !data) return <DashboardLoading stats={6} />
  if (error || !data) return <DashboardError message={error} onRetry={reload} />

  return (
    <div className="animate-fade-in space-y-8">
      <PageHeader title="Placement overview" description="College-wide placement activity at a glance." />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
        <StatCard label="Students" value={data.stats.total_students} icon={Users} tone="navy" />
        <StatCard label="Companies" value={data.stats.companies} icon={Building2} tone="blue" />
        <StatCard label="Active jobs" value={data.stats.active_jobs} icon={Briefcase} tone="amber" />
        <StatCard label="Active internships" value={data.stats.active_internships} icon={GraduationCap} tone="blue" />
        <StatCard label="Applications" value={data.stats.applications} icon={FileText} tone="navy" />
        <StatCard label="Selected" value={data.stats.selected_students} icon={CheckCircle2} tone="green" />
      </div>

      <section>
        <SectionHeader title="Recent activity" subtitle="Latest applications and companies onboarded" action={<Link to="/admin/reports" className="text-sm font-medium text-primary hover:underline">Open reports</Link>} />
        <div className="mt-4">
          {data.recent_activity.length === 0 ? (
            <div className="card"><EmptyState icon={History} title="No recent activity" description="New applications and companies will show up here." /></div>
          ) : (
            <ul className="card divide-y divide-hairline">
              {data.recent_activity.map((item, idx) => (
                <li key={`${item.type}-${item.at}-${idx}`} className="flex items-start justify-between gap-4 px-5 py-3.5 text-sm">
                  <span className="min-w-0 text-ink-soft">{item.description}</span>
                  {item.at && <span className="shrink-0 text-xs text-ink-faint">{formatRelativeTime(item.at)}</span>}
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </div>
  )
}
