import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { Briefcase, GraduationCap, ListChecks } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { CompanyStatusPanel } from '@/components/company/CompanyStatusPanel'
import { useApiResource } from '@/hooks/useApiResource'
import { useAuth } from '@/hooks/useAuth'
import { companyService } from '@/services/companyService'

function ApprovedDashboard() {
  const { data, isLoading, error, reload } = useApiResource(companyService.dashboard, 'company-dashboard')

  if (isLoading && !data) return <DashboardLoading stats={2} />
  if (error || !data) return <DashboardError message={error} onRetry={reload} />

  return (
    <>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatCard label="Job postings" value={data.stats.job_postings} icon={Briefcase} />
        <StatCard label="Internships" value={data.stats.internships} icon={GraduationCap} tone="green" />
      </div>
      <div className="card mt-6 flex items-start gap-4 p-6">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-tint text-indigo">
          <ListChecks className="h-5 w-5" aria-hidden="true" />
        </span>
        <div>
          <h2 className="text-sm font-semibold text-ink">Posting jobs is coming next</h2>
          <p className="mt-1 text-sm text-ink-soft">
            Your company is approved. Creating postings, reviewing applicants and scheduling interviews from this portal are the next
            step; until then the placement office posts on your behalf. Every posting you create will be published by a Placement Admin.
          </p>
        </div>
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
