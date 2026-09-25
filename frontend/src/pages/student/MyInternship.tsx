import { useEffect, useState } from 'react'
import { AlertTriangle, Briefcase } from 'lucide-react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { Button } from '@/components/ui/Button'
import { AttendanceSection } from '@/components/internship/AttendanceSection'
import { WeeklyReportsSection } from '@/components/internship/WeeklyReportsSection'
import { DocumentsSection } from '@/components/internship/DocumentsSection'
import { CompletionSection } from '@/components/internship/CompletionSection'
import { internshipAssignmentService } from '@/services/internshipAssignmentService'
import type { InternshipAssignmentDetail, InternshipAssignmentStatus } from '@/types/internshipAssignment'

const ASSIGNMENT_STATUS_TONE: Record<InternshipAssignmentStatus, 'neutral' | 'info' | 'success' | 'error'> = {
  ASSIGNED: 'info',
  ACTIVE: 'success',
  COMPLETED: 'neutral',
  CANCELLED: 'error',
}

export function MyInternshipPage() {
  const [assignment, setAssignment] = useState<InternshipAssignmentDetail | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  function load() {
    setIsLoading(true)
    setLoadError(false)
    internshipAssignmentService
      .current()
      .then(setAssignment)
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }

  useEffect(load, [])

  if (isLoading) {
    return (
      <div className="space-y-6">
        <SectionHeader title="My Internship" />
        <LoadingSkeletonLines count={5} className="h-16 w-full" />
      </div>
    )
  }

  if (loadError) {
    return (
      <div className="space-y-6">
        <SectionHeader title="My Internship" />
        <div className="card">
          <EmptyState
            icon={AlertTriangle}
            title="Couldn't load your internship"
            description="Something went wrong reaching the server. Check your connection and try again."
            action={
              <Button variant="secondary" onClick={load}>
                Retry
              </Button>
            }
          />
        </div>
      </div>
    )
  }

  if (!assignment) {
    return (
      <div className="space-y-6">
        <SectionHeader title="My Internship" />
        <div className="card">
          <EmptyState
            icon={Briefcase}
            title="No internship assigned yet"
            description="Once you're selected for an internship and assigned a faculty coordinator, your attendance, weekly reports, and documents will show up here."
          />
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-8">
      <SectionHeader title="My Internship" />

      <div className="card p-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-lg font-semibold text-ink">{assignment.internship?.title ?? 'Internship'}</p>
            <p className="text-sm text-ink-soft">{assignment.internship?.company?.name ?? 'Unknown company'}</p>
          </div>
          <StatusBadge tone={ASSIGNMENT_STATUS_TONE[assignment.status]}>{assignment.status}</StatusBadge>
        </div>

        <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
          <div>
            <dt className="text-xs text-ink-faint">Coordinator</dt>
            <dd className="text-ink">{assignment.faculty?.name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-ink-faint">Assigned</dt>
            <dd className="text-ink">{assignment.assigned_at ? assignment.assigned_at.slice(0, 10) : '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-ink-faint">Started</dt>
            <dd className="text-ink">{assignment.started_at ? assignment.started_at.slice(0, 10) : 'Not started yet'}</dd>
          </div>
          <div>
            <dt className="text-xs text-ink-faint">Ended</dt>
            <dd className="text-ink">{assignment.ended_at ? assignment.ended_at.slice(0, 10) : '—'}</dd>
          </div>
        </dl>
      </div>

      {/*
        Attendance and weekly reports both require the internship to
        have actually started (assignment.started_at) - both
        StoreAttendanceRequest and StoreWeeklyProgressReportRequest
        reject a date/week before started_at server-side, and
        started_at is only set once a faculty coordinator activates
        the assignment. Documents has no such restriction (an offer or
        joining letter is realistically uploaded before activation),
        so it's shown regardless of status.
      */}
      {assignment.started_at ? (
        <>
          <AttendanceSection assignmentId={assignment.id} />
          <WeeklyReportsSection assignmentId={assignment.id} />
        </>
      ) : (
        <div className="card">
          <EmptyState
            icon={Briefcase}
            title="Not started yet"
            description="Attendance and weekly reports open up once your faculty coordinator marks this internship active."
          />
        </div>
      )}

      <DocumentsSection assignmentId={assignment.id} />
      <CompletionSection assignmentStatus={assignment.status} />
    </div>
  )
}
