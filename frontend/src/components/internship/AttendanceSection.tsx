import { useEffect, useState } from 'react'
import { AlertTriangle, CalendarCheck, CalendarX, Flame, Percent } from 'lucide-react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { StatCard } from '@/components/ui/StatCard'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { Button } from '@/components/ui/Button'
import { attendanceService } from '@/services/attendanceService'
import type { AttendanceListItem, AttendanceSummary } from '@/types/attendance'

function toneFor(status: string): 'success' | 'error' | 'warning' | 'neutral' {
  switch (status) {
    case 'PRESENT':
      return 'success'
    case 'ABSENT':
      return 'error'
    case 'LEAVE':
      return 'warning'
    default:
      return 'neutral'
  }
}

/**
 * Read-only - see Api\Student\AttendanceController's docblock: no
 * write route exists on this resource for a student at all (marking/
 * correcting attendance is exclusively faculty/admin).
 */
export function AttendanceSection({ assignmentId }: { assignmentId: number }) {
  const [summary, setSummary] = useState<AttendanceSummary | null>(null)
  const [records, setRecords] = useState<AttendanceListItem[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  function load() {
    setIsLoading(true)
    setLoadError(false)
    attendanceService
      .forAssignment(assignmentId)
      .then((data) => {
        setSummary(data.summary)
        setRecords(data.records)
      })
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }

  useEffect(load, [assignmentId])

  return (
    <section className="space-y-4">
      <SectionHeader title="Attendance" subtitle="Marked by your faculty coordinator - read-only" />

      {isLoading ? (
        <LoadingSkeletonLines count={3} className="h-16 w-full" />
      ) : loadError ? (
        <div className="card">
          <EmptyState
            icon={AlertTriangle}
            title="Couldn't load attendance"
            description="Something went wrong reaching the server."
            action={
              <Button variant="secondary" onClick={load}>
                Retry
              </Button>
            }
          />
        </div>
      ) : !summary || summary.total_days === 0 ? (
        <div className="card">
          <EmptyState icon={CalendarCheck} title="No attendance recorded yet" description="Your coordinator will mark attendance once your internship starts." />
        </div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <StatCard label="Attendance" value={summary.attendance_percentage} suffix="%" icon={Percent} tone="navy" />
            <StatCard label="Present" value={summary.present_days} icon={CalendarCheck} tone="green" />
            <StatCard label="Absent" value={summary.absent_days} icon={CalendarX} tone="red" />
            <StatCard label="Streak" value={summary.current_streak} suffix=" days" icon={Flame} tone="amber" />
          </div>

          <div className="card divide-y divide-hairline">
            {records.slice(0, 10).map((record) => (
              <div key={record.id} className="flex items-center justify-between gap-4 px-5 py-3">
                <span className="text-sm text-ink">{record.attendance_date}</span>
                <div className="flex items-center gap-3">
                  {record.check_in_time && (
                    <span className="text-xs text-ink-faint">
                      {record.check_in_time}
                      {record.check_out_time ? ` – ${record.check_out_time}` : ''}
                    </span>
                  )}
                  <StatusBadge tone={toneFor(record.status)}>{record.status}</StatusBadge>
                </div>
              </div>
            ))}
          </div>
          {records.length > 10 && <p className="text-xs text-ink-faint">Showing the 10 most recent of {records.length} records.</p>}
        </>
      )}
    </section>
  )
}
