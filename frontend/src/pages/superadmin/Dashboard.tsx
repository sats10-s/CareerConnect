import { ShieldCheck, UserCog, Users } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { PageHeader } from '@/components/ui/PageHeader'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { useApiResource } from '@/hooks/useApiResource'
import { dashboardService } from '@/services/dashboardService'
import { ROLE_LABEL } from '@/utils/roles'
import type { Role } from '@/types/auth'
import type { SuperAdminDashboardData } from '@/types/dashboard'

type UserRow = SuperAdminDashboardData['recent_users'][number]
const roleLabel = (role: string) => ROLE_LABEL[role as Role] ?? role

// GET /super-admin/dashboard also returns `audit_events`, but it is a hard-coded 0 (no audit log exists yet), so it isn't shown.
export function SuperAdminDashboardPage() {
  const { data, isLoading, error, reload } = useApiResource(() => dashboardService.superAdmin(), 'superadmin-dashboard')

  if (isLoading && !data) return <DashboardLoading stats={5} />
  if (error || !data) return <DashboardError message={error} onRetry={reload} />

  return (
    <div className="animate-fade-in space-y-8">
      <PageHeader title="System overview" description="Accounts, roles and platform health." />

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
        <StatCard label="Users" value={data.stats.users} icon={Users} tone="navy" />
        <StatCard label="Students" value={data.stats.students} icon={Users} tone="blue" />
        <StatCard label="Faculty" value={data.stats.faculty} icon={UserCog} tone="blue" />
        <StatCard label="Admins" value={data.stats.admins} icon={ShieldCheck} tone="amber" />
        <StatCard label="Active accounts" value={data.stats.active_accounts} icon={ShieldCheck} tone="green" />
      </div>

      <section>
        <SectionHeader title="Recently created users" />
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
    </div>
  )
}
