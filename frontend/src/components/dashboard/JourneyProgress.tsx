import { Check } from 'lucide-react'
import { StatusBadge } from '@/components/ui/StatusBadge'

export interface JourneyStep {
  label: string
  /** 'complete' | 'current' | 'upcoming' - status is never conveyed by color alone: complete steps carry a check icon, the current step carries a text "Current" tag, on top of their distinct styling. */
  state: 'complete' | 'current' | 'upcoming'
}

/**
 * Horizontal stepped line. Completed steps: filled indigo + check icon.
 * The current step: ringed in gold - the ONLY place gold appears as a
 * ring/glow in the app - plus an explicit "Current" text tag (never
 * color-only). Future steps: outline only.
 */
export function JourneyProgress({ steps }: { steps: JourneyStep[] }) {
  return (
    <div className="overflow-x-auto">
      <ol className="flex min-w-max items-start gap-0 sm:min-w-0">
        {steps.map((step, index) => (
          <li key={step.label} className="flex flex-1 flex-col items-center px-1 first:pl-0 last:pr-0">
            <div className="flex w-full items-center">
              <div className={`h-px flex-1 ${index === 0 ? 'invisible' : step.state === 'upcoming' ? 'bg-border' : 'bg-indigo'}`} />
              <StepDot state={step.state} />
              <div
                className={`h-px flex-1 ${index === steps.length - 1 ? 'invisible' : step.state === 'complete' ? 'bg-indigo' : 'bg-border'}`}
              />
            </div>
            <p
              className={`mt-2 whitespace-nowrap text-xs font-medium ${
                step.state === 'upcoming' ? 'text-ink-faint' : 'text-ink'
              }`}
            >
              {step.label}
            </p>
            {step.state === 'current' && (
              <span className="mt-1">
                <StatusBadge tone="gold">Current</StatusBadge>
              </span>
            )}
          </li>
        ))}
      </ol>
    </div>
  )
}

function StepDot({ state }: { state: JourneyStep['state'] }) {
  if (state === 'complete') {
    return (
      <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo text-[color:var(--color-primary-ink)]">
        <Check className="h-3.5 w-3.5" strokeWidth={3} />
      </span>
    )
  }
  if (state === 'current') {
    return (
      <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface ring-2 ring-gold">
        <span className="h-2 w-2 rounded-full bg-indigo" />
      </span>
    )
  }
  return <span className="h-6 w-6 shrink-0 rounded-full border-2 border-border bg-surface" />
}
