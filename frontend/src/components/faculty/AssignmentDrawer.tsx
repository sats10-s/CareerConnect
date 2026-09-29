import type { ReactNode } from 'react'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import { DetailItem, InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { useApiResource } from '@/hooks/useApiResource'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import { ASSIGNMENT_STATUS } from '@/utils/statusMeta'
import type { AssignmentDetail } from '@/types/coordination'

interface AssignmentDrawerProps {
  assignmentId: number | null
  onClose: () => void
  /** Role-specific fetcher (faculty and admin read the same shape from different routes). */
  loader: (id: number) => Promise<AssignmentDetail>
  /** Role-specific shortcuts shown under the details (e.g. "View attendance"). */
  footer?: (assignment: AssignmentDetail) => ReactNode
  /** Role-specific extra sections (admin adds notes/assigned-by/faculty history). */
  extra?: (assignment: AssignmentDetail) => ReactNode
}

export function AssignmentDrawer({ assignmentId, onClose, loader, footer, extra }: AssignmentDrawerProps) {
  const { data, isLoading, error, reload } = useApiResource(() => loader(assignmentId as number), assignmentId)

  const history: TimelineItem[] = (data?.status_history ?? []).map((entry, index, all) => ({
    id: entry.id,
    title: `${entry.from_status ? `${ASSIGNMENT_STATUS[entry.from_status]?.label ?? entry.from_status} → ` : ''}${ASSIGNMENT_STATUS[entry.to_status]?.label ?? entry.to_status}`,
    description: entry.remarks ?? undefined,
    timestamp: `${formatDateTime(entry.created_at)}${entry.changed_by ? ` · ${entry.changed_by.name}` : ''}`,
    status: index === all.length - 1 ? 'current' : 'completed',
  }))

  return (
    <Drawer
      open={assignmentId !== null}
      onClose={onClose}
      title="Internship assignment"
      width="md"
      footer={data && footer ? footer(data) : undefined}
    >
      {isLoading ? (
        <div className="space-y-4" aria-busy="true">
          <LoadingSkeleton className="h-10 w-2/3" />
          <LoadingSkeleton className="h-4 w-full" />
          <LoadingSkeleton className="h-4 w-5/6" />
          <LoadingSkeleton className="h-4 w-4/6" />
        </div>
      ) : error ? (
        <ErrorState description={error} onRetry={reload} />
      ) : data ? (
        <div className="space-y-6">
          <div className="flex items-start justify-between gap-3">
            <PersonCell student={data.student} subtitle={data.student ? `${data.student.roll_number} · ${data.student.department ?? ''}` : undefined} />
            <MetaBadge map={ASSIGNMENT_STATUS} value={data.status} />
          </div>

          <div className="rounded-xl border border-hairline p-3">
            <InternshipCell internship={data.internship} />
          </div>

          <dl className="grid grid-cols-2 gap-4">
            <DetailItem label="Year">{data.student?.year ? `Year ${data.student.year}` : null}</DetailItem>
            <DetailItem label="Faculty coordinator">{data.faculty?.name}</DetailItem>
            <DetailItem label="Assigned">{formatDate(data.assigned_at)}</DetailItem>
            <DetailItem label="Started">{formatDate(data.started_at)}</DetailItem>
            <DetailItem label="Ended">{formatDate(data.ended_at)}</DetailItem>
            <DetailItem label="Application">{data.application ? `#${data.application.id} · ${data.application.status}` : null}</DetailItem>
          </dl>

          {extra?.(data)}

          {history.length > 0 && (
            <section>
              <h3 className="mb-3 text-sm font-semibold text-ink">Status history</h3>
              <Timeline items={history} />
            </section>
          )}
        </div>
      ) : null}
    </Drawer>
  )
}
