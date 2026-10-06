import { useEffect } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { Briefcase, Clock, ListChecks, Trophy, Users } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { CompanyStatusPanel } from '@/components/company/CompanyStatusPanel'
import { useApiResource } from '@/hooks/useApiResource'
import { useAuth } from '@/hooks/useAuth'
import { companyService } from '@/services/companyService'
import { formatDateTime } from '@/utils/dateFormat'

function ApprovedDashboard() {
  const { data, isLoading, error, reload } = useApiResource(companyService.dashboard, 'company-dashboard')

  if (isLoading && !data) return <DashboardLoading stats={4} />
  if (error || !data) return <DashboardError message={error} onRetry={reload} />
  const { stats } = data

  return (
    <>
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Live postings" value={stats.live_postings} icon={Briefcase} tone="green" />
        <StatCard label="Waiting for review" value={stats.in_review} icon={Clock} tone="amber" />
        <StatCard label="Active applicants" value={stats.applicants} icon={Users} />
        <StatCard label="Selected" value={stats.selected} icon={Trophy} tone="green" />
      </div>

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <section className="card p-5">
          <div className="mb-4 flex items-center justify-between gap-3">
            <h2 className="text-sm font-semibold text-ink">Upcoming interviews</h2>
            <Link to="/company/interviews" className="text-xs font-semibold text-primary hover:text-primary-hov">View all</Link>
          </div>
          {data.upcoming_interviews.length === 0 ? (
            <p className="text-sm text-ink-faint">Nothing scheduled. Shortlist applicants to start interviewing.</p>
          ) : (
            <ul className="divide-y divide-hairline">
              {data.upcoming_interviews.map((iv) => (
                <li key={iv.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">{iv.student?.name ?? 'Applicant'}</p>
                    <p className="truncate text-xs text-ink-faint">{iv.opportunity?.title} · Round {iv.round}</p>
                  </div>
                  <p className="shrink-0 text-xs text-ink-soft">{formatDateTime(iv.scheduled_at)}</p>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="card p-5">
          <h2 className="mb-4 text-sm font-semibold text-ink">Pipeline</h2>
          <dl className="space-y-3 text-sm">
            <div className="flex justify-between"><dt className="text-ink-soft">Drafts</dt><dd className="tabular-nums text-ink">{stats.drafts}</dd></div>
            <div className="flex justify-between"><dt className="text-ink-soft">Waiting for placement office</dt><dd className="tabular-nums text-ink">{stats.in_review}</dd></div>
            <div className="flex justify-between"><dt className="text-ink-soft">Shortlisted or interviewing</dt><dd className="tabular-nums text-ink">{stats.shortlisted}</dd></div>
          </dl>
          <div className="mt-5 flex flex-wrap gap-3">
            <Link to="/company/postings" className="btn-primary"><ListChecks className="h-4 w-4" />Manage postings</Link>
            <Link to="/company/applicants" className="btn-secondary">Review applicants</Link>
          </div>
        </section>
      </div>
    </>
  )
}

/**
 * /company/dashboard. Reads the company's live status from /company/me (the
 * signed-in user object can be stale after a decision) and shows either the
 * pending/rejected/suspended status page or the portal dashboard.
 */
export function CompanyDashboardPage() {
  const { user, refresh } = useAuth()
  const location = useLocation()
  const { data: company, isLoading, error, reload } = useApiResource(companyService.me, 'company-me')
  const justRegistered = Boolean((location.state as { justRegistered?: unknown } | null)?.justRegistered)

  useEffect(() => {
    if (company && user?.company && company.status !== user.company.status) void refresh()
  }, [company, user?.company, refresh])

  if (isLoading && !company) return <DashboardLoading stats={2} />
  if (error || !company) return <DashboardError message={error} onRetry={reload} />

  return (
    <div className="animate-fade-in">
      <PageHeader title={company.name} description={company.status === 'APPROVED' ? 'Your recruiting overview.' : 'Company account status.'} />
      {justRegistered && company.status === 'PENDING' && (
        <div role="status" className="mx-auto mb-6 max-w-2xl rounded-lg border border-success bg-success-bg px-4 py-3 text-sm text-success">
          Registration received. We’ve sent it to a Super Admin for review.
        </div>
      )}
      {company.status === 'APPROVED' ? <ApprovedDashboard /> : <CompanyStatusPanel company={company} />}
    </div>
  )
}
