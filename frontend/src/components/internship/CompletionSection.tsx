import { useEffect, useState } from 'react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { internshipCompletionService } from '@/services/internshipCompletionService'
import { formatDate } from '@/utils/dateFormat'
import { formatInr } from '@/utils/numberFormat'
import { PPO_STATUS } from '@/utils/statusMeta'
import type { InternshipCompletionDetail, PpoRecordDetail } from '@/types/internshipCompletion'

/**
 * Read-only for the student on both records (no create/update route
 * exists on either - see Api\Student\InternshipCompletionController/
 * PpoRecordController). Renders nothing at all when both come back
 * null (the ordinary state for most of an internship's lifetime) -
 * showing an empty "no completion record" card here, above an
 * Attendance/Weekly Reports/Documents section that's already visibly
 * in progress, would read as an error rather than the expected state.
 */
export function CompletionSection({ assignmentStatus }: { assignmentStatus: string }) {
  const [completion, setCompletion] = useState<InternshipCompletionDetail | null>(null)
  const [ppo, setPpo] = useState<PpoRecordDetail | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    setIsLoading(true)
    Promise.all([internshipCompletionService.completion(), internshipCompletionService.ppo()])
      .then(([completionData, ppoData]) => {
        setCompletion(completionData)
        setPpo(ppoData)
      })
      .finally(() => setIsLoading(false))
  }, [])

  if (isLoading) {
    return assignmentStatus === 'COMPLETED' ? (
      <section className="space-y-4">
        <SectionHeader title="Completion & PPO" />
        <LoadingSkeletonLines count={2} className="h-16 w-full" />
      </section>
    ) : null
  }

  if (!completion && !ppo) return null

  return (
    <section className="space-y-4">
      <SectionHeader title="Completion & PPO" />

      {completion && (
        <div className="card p-5">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold text-ink">Internship completion</p>
            <StatusBadge tone={completion.outcome === 'COMPLETED' ? 'success' : 'error'}>
              {completion.outcome === 'COMPLETED' ? 'Completed' : 'Not completed'}
            </StatusBadge>
          </div>
          {completion.completion_date && <p className="mt-1 text-xs text-ink-faint">{formatDate(completion.completion_date)}</p>}
          {completion.final_remarks && <p className="mt-3 text-sm text-ink-soft">{completion.final_remarks}</p>}
        </div>
      )}

      {ppo && (
        <div className="card p-5">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold text-ink">Pre-Placement Offer</p>
            <StatusBadge tone={ppo.status === 'ACCEPTED' ? 'gold' : ppo.status === 'DECLINED' ? 'neutral' : 'info'}>{PPO_STATUS[ppo.status as keyof typeof PPO_STATUS]?.label ?? ppo.status}</StatusBadge>
          </div>
          <p className="mt-1 text-sm text-ink">
            {ppo.designation ?? 'Offer'} · {ppo.company?.name ?? 'Unknown company'}
          </p>
          {ppo.package && <p className="text-xs text-ink-faint">Package: {formatInr(ppo.package)}</p>}
          {ppo.remarks && <p className="mt-2 text-sm text-ink-soft">{ppo.remarks}</p>}
        </div>
      )}
    </section>
  )
}
