import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import { DetailItem, InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { ReviewActions } from './ReviewActions'
import { useApiResource } from '@/hooks/useApiResource'
import { facultyService, type ReviewAction } from '@/services/facultyService'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import { WEEKLY_REPORT_STATUS, metaFor } from '@/utils/statusMeta'

interface WeeklyReportDrawerProps {
  reportId: number | null
  onClose: () => void
  /** Called after a review action succeeds so the list behind the drawer refreshes. */
  onChanged: () => void
}

function TextBlock({ title, text }: { title: string; text: string | null }) {
  return (
    <section>
      <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">{title}</h3>
      {text ? <p className="whitespace-pre-line text-sm leading-relaxed text-ink">{text}</p> : <p className="text-sm text-ink-faint">Not provided</p>}
    </section>
  )
}

export function WeeklyReportDrawer({ reportId, onClose, onChanged }: WeeklyReportDrawerProps) {
  const { data, isLoading, error, reload } = useApiResource(() => facultyService.weeklyReport(reportId as number), reportId)

  async function review(action: ReviewAction, remarks?: string) {
    await facultyService.reviewWeeklyReport(reportId as number, action, remarks)
    reload()
    onChanged()
  }

  const history: TimelineItem[] = (data?.history ?? []).map((entry, index, all) => ({
    id: entry.id,
    title: `${entry.from_status ? `${metaFor(WEEKLY_REPORT_STATUS, entry.from_status).label} → ` : ''}${metaFor(WEEKLY_REPORT_STATUS, entry.to_status).label}`,
    description: entry.remarks ?? undefined,
    timestamp: `${formatDateTime(entry.created_at)}${entry.changed_by ? ` · ${entry.changed_by.name}` : ''}`,
    status: index === all.length - 1 ? 'current' : 'completed',
  }))

  return (
    <Drawer open={reportId !== null} onClose={onClose} title={data ? `Week ${data.week_number} report` : 'Weekly report'} width="lg">
      {isLoading && !data ? (
        <div className="space-y-4" aria-busy="true">
          <LoadingSkeleton className="h-10 w-2/3" />
          <LoadingSkeleton className="h-4 w-full" />
          <LoadingSkeleton className="h-4 w-5/6" />
          <LoadingSkeleton className="h-24 w-full" />
        </div>
      ) : error ? (
        <ErrorState description={error} onRetry={reload} />
      ) : data ? (
        <div className="space-y-6">
          <div className="flex items-start justify-between gap-3">
            <PersonCell student={data.student} />
            <MetaBadge map={WEEKLY_REPORT_STATUS} value={data.status} />
          </div>
          <div className="rounded-xl border border-hairline p-3">
            <InternshipCell internship={data.internship} />
          </div>
          <dl className="grid grid-cols-2 gap-4">
            <DetailItem label="Week">{`${formatDate(data.week_start_date)} – ${formatDate(data.week_end_date)}`}</DetailItem>
            <DetailItem label="Submitted">{formatDateTime(data.student_submitted_at)}</DetailItem>
          </dl>

          <TextBlock title="Work done" text={data.work_done} />
          <TextBlock title="Learning outcomes" text={data.learning_outcomes} />
          <TextBlock title="Challenges" text={data.challenges} />
          <TextBlock title="Plan for next week" text={data.next_week_plan} />

          {data.faculty_review_remarks && (
            <section className="rounded-xl bg-sidebar p-3">
              <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Your last remarks</h3>
              <p className="whitespace-pre-line text-sm text-ink">{data.faculty_review_remarks}</p>
            </section>
          )}

          <section className="border-t border-hairline pt-5">
            <h3 className="mb-3 text-sm font-semibold text-ink">Review</h3>
            <ReviewActions key={data.status} status={data.status} noun="report" onReview={review} />
          </section>

          {history.length > 0 && (
            <section>
              <h3 className="mb-3 text-sm font-semibold text-ink">History</h3>
              <Timeline items={history} />
            </section>
          )}
        </div>
      ) : null}
    </Drawer>
  )
}
