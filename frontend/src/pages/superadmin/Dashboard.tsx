import { useEffect, useState } from 'react'
import { History, ShieldCheck, UserCog, Users } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { Card, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { dashboardService } from '@/services/dashboardService'
import type { SuperAdminDashboardData } from '@/types/dashboard'

export function SuperAdminDashboardPage() {
  const [data, setData] = useState<SuperAdminDashboardData | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    dashboardService.superAdmin().then(setData).finally(() => setIsLoading(false))
  }, [])

  if (isLoading) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (!data) return <p className="text-sm text-gray-500">Unable to load dashboard data right now.</p>

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-gray-900">System overview</h2>
        <p className="mt-1 text-sm text-gray-500">Accounts, roles and platform health.</p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard label="Users" value={data.stats.users} icon={Users} tone="navy" />
        <StatCard label="Students" value={data.stats.students} icon={Users} tone="blue" />
        <StatCard label="Faculty" value={data.stats.faculty} icon={UserCog} tone="blue" />
        <StatCard label="Admins" value={data.stats.admins} icon={ShieldCheck} tone="amber" />
        <StatCard label="Active Accounts" value={data.stats.active_accounts} icon={ShieldCheck} tone="green" />
        <StatCard label="Audit Events" value={data.stats.audit_events} icon={History} tone="navy" />
      </div>

      <Card>
        <CardHeader title="Recently created users" />
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-5 py-3">Name</th>
                <th className="px-5 py-3">Email</th>
                <th className="px-5 py-3">Role</th>
                <th className="px-5 py-3">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {data.recent_users.map((u) => (
                <tr key={u.id}>
                  <td className="px-5 py-3 font-medium text-gray-900">{u.name}</td>
                  <td className="px-5 py-3 text-gray-600">{u.email}</td>
                  <td className="px-5 py-3 text-gray-600">{u.role}</td>
                  <td className="px-5 py-3">
                    <Badge tone={u.is_active ? 'success' : 'error'}>{u.is_active ? 'Active' : 'Inactive'}</Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  )
}
