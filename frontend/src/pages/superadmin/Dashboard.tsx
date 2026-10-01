import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, History, ShieldCheck, UserCog, Users } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { PageHeader } from '@/components/ui/PageHeader'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { UserDrawer } from '@/components/superadmin/UserDrawer'
import { useApiResource } from '@/hooks/useApiResource'
import { dashboardService } from '@/services/dashboardService'
import { superAdminService } from '@/services/superAdminService'
import { toneForAuditAction } from '@/utils/auditMeta'
import { formatDateTime } from '@/utils/dateFormat'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import { ROLE_LABEL } from '@/utils/roles'
import type { Role } from '@/types/auth'
import type { SuperAdminDashboardData } from '@/types/dashboard'

type UserRow = SuperAdminDashboardData['recent_users'][number]
const roleLabel = (role: string) => ROLE_LABEL[role as Role] ?? role

function ViewAll({ to, children }: { to: string; children: string }) {
  return (
    <Link to={to} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
      {children}
      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
    </Link>
  )
}

function RecentActivity() {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.auditLogs({ per_page: 8 }).then((d) => d.items), 'superadmin-recent-activity')
  if (isLoading && !data) return <div className="space-y-3 py-3">{[0, 1, 2, 3].map((i) => <LoadingSkeleton key={i} className="h-10 w-full" />)}</div>
  if (error) return <p className="py-4 text-sm text-ink-soft">Couldn't load recent activity. <button type="button" className="font-medium text-primary hover:underline" onClick={reload}>Retry</button></p>
  if (!data?.length) return <p className="py-6 text-sm text-ink-soft">Sign-ins and changes will appear here as they happen.</p>
  return (
    <ul className="divide-y divide-hairline">
      {data.map((e) => (
        <li key={e.id} className="flex items-start justify-between gap-3 py-3">
          <div className="min-w-0">
            <p className="line-clamp-2 text-sm text-ink">{e.description}</p>
            {e.created_at && <time dateTime={e.created_at} title={formatDateTime(e.created_at)} className="text-xs text-ink-faint">{formatRelativeTime(e.created_at)}</time>}
          </div>
          <StatusBadge tone={toneForAuditAction(e.action)}>{e.action_label}</StatusBadge>
        </li>
      ))}
    </ul>
  )
}

export function SuperAdminDashboardPage() {
  const { data, isLoading, error, reload } = useApiResource(() => dashboardService.superAdmin(), 'superadmin-dashboard')
  const settings = useApiResource(() => superAdminService.settings.get(), 'superadmin-dashboard-settings')
  const [openId, setOpenId] = useState<number | null>(null)

  if (isLoading && !data) return <DashboardLoading stats={6} />
  if (error || !data) return <DashboardError message={error} onRetry={reload} />

  const subtitle = settings.data
    ? [settings.data.institution_name, settings.data.academic_year && `Academic year ${settings.data.academic_year}`].filter(Boolean).join(' · ')
    : 'Accounts, roles and platform health.'
  const inactive = data.stats.users - data.stats.active_accounts

  return (
    <div className="animate-fade-in space-y-8">
      <PageHeader title="System overview" description={subtitle} />

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Users" value={data.stats.users} icon={Users} tone="navy" />
        <StatCard label="Students" value={data.stats.students} icon={Users} tone="blue" />
        <StatCard label="Faculty" value={data.stats.faculty} icon={UserCog} tone="blue" />
        <StatCard label="Admins" value={data.stats.admins} icon={ShieldCheck} tone="amber" />
        <StatCard label="Active accounts" value={data.stats.active_accounts} icon={ShieldCheck} tone="green" />
        <StatCard label="Audit events" value={data.stats.audit_events.toLocaleString()} icon={History} tone="navy" />
      </div>

      {inactive > 0 && (
        <p className="text-sm text-ink-soft">
          {inactive} account{inactive === 1 ? ' is' : 's are'} deactivated.{' '}
          <Link to="/super-admin/users?status=inactive" className="font-medium text-primary hover:underline">Review</Link>
        </p>
      )}

      <div className="grid grid-cols-1 gap-8 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <section>
          <SectionHeader title="Recently created users" action={<ViewAll to="/super-admin/users">All users</ViewAll>} />
          <div className="mt-4">
            {data.recent_users.length === 0 ? (
              <div className="card"><EmptyState icon={Users} title="No users yet" description="New accounts show up here as they are created." /></div>
            ) : (
              <DataTable
                columns={[
                  { key: 'name', header: 'Name', render: (u: UserRow) => <span className="font-medium text-ink">{u.name}</span> },
                  { key: 'email', header: 'Email', render: (u: UserRow) => <span className="text-ink-soft">{u.email}</span> },
                  { key: 'role', header: 'Role', render: (u: UserRow) => <span className="text-ink-soft">{roleLabel(u.role)}</span> },
                  { key: 'status', header: 'Status', render: (u: UserRow) => <StatusBadge tone={u.is_active ? 'success' : 'error'}>{u.is_active ? 'Active' : 'Inactive'}</StatusBadge> },
                ]}
                rows={data.recent_users}
                rowKey={(u) => u.id}
                onRowClick={(u) => setOpenId(u.id)}
                renderMobileCard={(u) => (
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-ink">{u.name}</p>
                      <p className="truncate text-xs text-ink-faint">{u.email} · {roleLabel(u.role)}</p>
                    </div>
                    <StatusBadge tone={u.is_active ? 'success' : 'error'}>{u.is_active ? 'Active' : 'Inactive'}</StatusBadge>
                  </div>
                )}
              />
            )}
          </div>
        </section>

        <section>
          <SectionHeader title="Recent activity" action={<ViewAll to="/super-admin/audit-logs">Audit log</ViewAll>} />
          <RecentActivity />
        </section>
      </div>

      <UserDrawer id={openId} onClose={() => setOpenId(null)} onChanged={reload} />
    </div>
  )
}
