import { useCallback, useEffect, useState } from 'react'
import { Bell, CheckCheck } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { Tabs } from '@/components/ui/Tabs'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { ErrorState } from '@/components/ui/ErrorState'
import { toast } from '@/components/ui/Toast'
import { parseApiError } from '@/utils/apiError'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { useAuth } from '@/hooks/useAuth'
import { notificationService } from '@/services/notificationService'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { NotificationItem, NotificationListMeta } from '@/types/notification'

type FilterKey = 'all' | 'unread'

/**
 * Role-agnostic notification list - rendered at both /student/notifications
 * and /admin/notifications (see pages/student/Notifications.tsx and
 * pages/admin/Notifications.tsx, thin wrappers matching the existing
 * DashboardLayout/role-layout convention). Reads the acting role from
 * useAuth() and resolves the right API prefix via notificationService.
 */
export function NotificationsPage() {
  const { user } = useAuth()
  const role = user?.role

  const [filter, setFilter] = useState<FilterKey>('all')
  const [page, setPage] = useState(1)
  const [items, setItems] = useState<NotificationItem[]>([])
  const [meta, setMeta] = useState<NotificationListMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [markingId, setMarkingId] = useState<number | null>(null)
  const [isMarkingAll, setIsMarkingAll] = useState(false)

  const load = useCallback(() => {
    if (!role) return
    setIsLoading(true)
    setLoadError(null)
    notificationService
      .list(role, { status: filter === 'unread' ? 'unread' : undefined, page, per_page: 15 })
      .then((data) => {
        setItems(data.items)
        setMeta(data.meta)
      })
      .catch((err: unknown) => setLoadError(parseApiError(err, 'Unable to load your notifications.').message))
      .finally(() => setIsLoading(false))
  }, [role, filter, page])

  useEffect(() => {
    load()
  }, [load])

  function handleFilterChange(next: FilterKey) {
    setFilter(next)
    setPage(1)
  }

  function handleMarkRead(item: NotificationItem) {
    if (!role || item.read_at) return
    setMarkingId(item.id)
    notificationService
      .markRead(role, item.id)
      .then((updated) => {
        setItems((prev) => prev.map((n) => (n.id === updated.id ? updated : n)))
      })
      .catch((err: unknown) => toast('error', parseApiError(err, 'Unable to mark that notification as read.').message))
      .finally(() => setMarkingId(null))
  }

  function handleMarkAllRead() {
    if (!role) return
    setIsMarkingAll(true)
    notificationService
      .markAllRead(role)
      .then(() => load())
      .catch((err: unknown) => toast('error', parseApiError(err, 'Unable to mark notifications as read.').message))
      .finally(() => setIsMarkingAll(false))
  }

  const hasUnread = items.some((item) => !item.read_at)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Notifications"
        description="Everything that's happened on your account."
        action={
          <Button variant="secondary" onClick={handleMarkAllRead} disabled={isMarkingAll || !hasUnread}>
            <CheckCheck className="h-4 w-4" />
            Mark all as read
          </Button>
        }
      />

      <Tabs
        items={[
          { key: 'all', label: 'All' },
          { key: 'unread', label: 'Unread' },
        ]}
        active={filter}
        onChange={(key) => handleFilterChange(key as FilterKey)}
      />

      <div className="card">
        {loadError ? (
          <div role="alert"><ErrorState description={loadError} onRetry={load} /></div>
        ) : isLoading ? (
          <div className="p-5">
            <LoadingSkeletonLines count={5} className="h-14 w-full" />
          </div>
        ) : items.length === 0 ? (
          <EmptyState
            icon={Bell}
            title={filter === 'unread' ? "You're all caught up" : 'No notifications yet'}
            description={
              filter === 'unread'
                ? 'Nothing unread right now.'
                : role === 'STUDENT'
                  ? "You'll see updates about your applications, interviews, and more here."
                  : 'Updates meant for you - such as announcements and activity that needs your attention - will show up here.'
            }
          />
        ) : (
          <ul>
            {items.map((item) => {
              const isUnread = !item.read_at
              return (
                <li
                  key={item.id}
                  className={`flex items-start gap-3 border-b border-hairline px-5 py-4 last:border-0 ${
                    isUnread ? 'bg-primary-tint/40' : ''
                  }`}
                >
                  <span
                    className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${isUnread ? 'bg-gold' : 'bg-hairline'}`}
                    aria-hidden="true"
                  />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-3">
                      <p className="text-sm font-semibold text-ink">{item.title}</p>
                      <span className="shrink-0 text-xs text-ink-soft">{formatRelativeTime(item.created_at)}</span>
                    </div>
                    <p className="mt-0.5 text-sm text-ink-soft">{item.message}</p>
                  </div>
                  {isUnread && (
                    <button
                      type="button"
                      onClick={() => handleMarkRead(item)}
                      disabled={markingId === item.id}
                      className="shrink-0 self-center rounded-lg px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary-tint disabled:opacity-60"
                    >
                      Mark read
                    </button>
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </div>

      {meta && meta.last_page > 1 && <Pagination page={meta.current_page} totalPages={meta.last_page} onChange={setPage} />}
    </div>
  )
}
