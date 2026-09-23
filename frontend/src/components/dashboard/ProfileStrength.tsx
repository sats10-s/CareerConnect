import { Card, CardHeader } from '@/components/ui/Card'

/** Profile completion, from the same stat the backend already computes (StudentDashboardController::profileCompletion). */
export function ProfileStrength({ percentage }: { percentage: number }) {
  const clamped = Math.max(0, Math.min(100, percentage))
  const isComplete = clamped >= 100

  return (
    <Card>
      <CardHeader title="Profile strength" subtitle="Keep this complete so recruiters see the full picture" />
      <div className="px-5 py-5">
        <div className="flex items-baseline justify-between">
          <span className="text-3xl font-semibold tabular-nums text-ink">{clamped}%</span>
          {isComplete && <span className="text-xs font-medium text-gold-ink">Complete</span>}
        </div>
        <div className="mt-3 h-2 overflow-hidden rounded-full bg-sidebar" role="progressbar" aria-valuenow={clamped} aria-valuemin={0} aria-valuemax={100}>
          <div
            className={`h-full rounded-full transition-[width] ${isComplete ? 'bg-gold' : 'bg-indigo'}`}
            style={{ width: `${clamped}%` }}
          />
        </div>
      </div>
    </Card>
  )
}
