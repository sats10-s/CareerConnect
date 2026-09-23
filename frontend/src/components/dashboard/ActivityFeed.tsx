import { SectionHeader } from '@/components/ui/SectionHeader'
import type { StudentDashboardData } from '@/types/dashboard'

/**
 * Timeline style with a connecting vertical line and small dots - a
 * gold dot marks unread (the achievement/attention-signaling use of
 * gold extends naturally to "this is new"), a neutral dot marks read.
 * Deliberately not icon-in-a-box, which reads as generic SaaS.
 */
export function ActivityFeed({ notifications }: { notifications: StudentDashboardData['notifications'] }) {
  return (
    <section>
      <SectionHeader title="Activity" />
      {notifications.length === 0 ? (
        <p className="py-8 text-center text-sm text-ink-faint">No activity yet.</p>
      ) : (
        <ol className="mt-1">
          {notifications.map((note, index) => (
            <li key={note.id} className="relative flex gap-3 pb-5 pl-1 last:pb-0">
              {index !== notifications.length - 1 && (
                <span className="absolute left-[7px] top-3 h-full w-px bg-border" aria-hidden="true" />
              )}
              <span
                className={`relative mt-1.5 h-3 w-3 shrink-0 rounded-full ${note.read ? 'bg-border' : 'bg-gold'}`}
                aria-hidden="true"
              />
              <p className="text-sm text-ink-soft">
                {note.message}
                {!note.read && <span className="ml-2 text-xs font-medium text-gold-ink">New</span>}
              </p>
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}
