import { useEffect, useState } from 'react'
import { AlertTriangle, ClipboardList, GraduationCap, Users } from 'lucide-react'
import { StatCard } from '@/components/ui/StatCard'
import { Card, CardHeader } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { dashboardService } from '@/services/dashboardService'
import { useAuth } from '@/hooks/useAuth'
import type { FacultyDashboardData } from '@/types/dashboard'

export function FacultyDashboardPage() {
  const { user } = useAuth()
  const [data, setData] = useState<FacultyDashboardData | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    dashboardService.faculty().then(setData).finally(() => setIsLoading(false))
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
        <h2 className="text-xl font-bold text-gray-900">Welcome back, {user?.name}</h2>
        <p className="mt-1 text-sm text-gray-500">An overview of the students you mentor.</p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <StatCard label="Assigned Students" value={data.stats.assigned_students} icon={Users} tone="navy" />
        <StatCard label="Active Internships" value={data.stats.active_internships} icon={GraduationCap} tone="blue" />
        <StatCard label="Pending Reports" value={data.stats.pending_reports} icon={ClipboardList} tone="amber" />
        <StatCard label="Attendance Alerts" value={data.stats.attendance_alerts} icon={AlertTriangle} tone="red" />
      </div>

      <Card>
        <CardHeader title="My students" subtitle="Students currently mentored by you" />
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-5 py-3">Name</th>
                <th className="px-5 py-3">Roll Number</th>
                <th className="px-5 py-3">Department</th>
                <th className="px-5 py-3">Batch</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {data.students.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-5 py-8 text-center text-gray-500">No students assigned yet.</td>
                </tr>
              )}
              {data.students.map((s) => (
                <tr key={s.id}>
                  <td className="px-5 py-3 font-medium text-gray-900">{s.name}</td>
                  <td className="px-5 py-3 text-gray-600">{s.roll_number}</td>
                  <td className="px-5 py-3 text-gray-600">{s.department}</td>
                  <td className="px-5 py-3 text-gray-600">{s.batch}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  )
}
