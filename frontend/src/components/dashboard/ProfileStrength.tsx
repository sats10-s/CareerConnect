import { Card, CardHeader } from '@/components/ui/Card'
import { ProgressBar } from '@/components/ui/ProgressBar'

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
          {isComplete && <span className="text-xs font-medium text-accent-str">Complete</span>}
        </div>
        <ProgressBar value={clamped} tone={isComplete ? 'accent' : 'primary'} className="mt-3" />
      </div>
    </Card>
  )
}
