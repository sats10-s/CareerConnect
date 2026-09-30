import { Link } from 'react-router-dom'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { NotificationItem } from '@/types/notification'

/**
 * The student's latest real notifications (GET /student/notifications) as a
 * short timeline: mint dot = unread, hairline dot = read. The dashboard
 * endpoint's own `notifications` field is a fixed placeholder message, so it
 * isn't used.
 */
export function ActivityFeed({ notifications }: { notifications: NotificationItem[] | null }) {
  return (
    <section>
      <SectionHeader title="Activity" action={<Link to="/student/notifications" className="text-sm font-medium text-primary hover:underline">View all</Link>} />
      {notifications === null ? (
        <p className="py-8 text-center text-sm text-ink-faint">Activity couldn't be loaded right now.</p>
      ) : notifications.length === 0 ? (
        <p className="py-8 text-center text-sm text-ink-faint">Nothing yet - updates on your applications and internship will appear here.</p>
      ) : (
        <ol className="mt-4">
          {notifications.map((note, index) => (
            <li key={note.id} className="relative flex gap-3 pb-5 pl-1 last:pb-0">
              {index !== notifications.length - 1 && <span className="absolute left-[7px] top-3 h-full w-px bg-border" aria-hidden="true" />}
              <span className={`relative mt-1.5 h-3 w-3 shrink-0 rounded-full ${note.read_at ? 'bg-border' : 'bg-primary'}`} aria-hidden="true" />
              <div className="min-w-0">
                <p className="text-sm text-ink">
                  {note.title}
                  {!note.read_at && <span className="ml-2 text-xs font-medium text-primary">New</span>}
                </p>
                <p className="text-xs text-ink-faint">{formatRelativeTime(note.created_at)}</p>
              </div>
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}
