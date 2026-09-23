import { CalendarClock } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'

export interface Interview {
  id: number
  company: string
  role: string
  scheduledFor: string
}

/**
 * NOTE: the current dashboard API has no interview-scheduling data
 * (date/time/location) - that would need a new backend field, out of
 * scope for this presentation-only pass. Built and composed per spec;
 * renders its honest empty state until that data exists, rather than
 * inventing a fake interview date.
 */
export function UpcomingInterview({ interviews = [] }: { interviews?: Interview[] }) {
  return (
    <Card>
      <CardHeader title="Upcoming interview" />
      <div className="px-5 py-5">
        {interviews.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-3 text-center">
            <CalendarClock className="h-5 w-5 text-ink-faint" />
            <p className="text-sm text-ink-faint">No interviews scheduled yet.</p>
          </div>
        ) : (
          <ul className="space-y-3">
            {interviews.map((interview) => (
              <li key={interview.id}>
                <p className="text-sm font-medium text-ink">{interview.role} · {interview.company}</p>
                <p className="text-xs text-ink-faint">{interview.scheduledFor}</p>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}
