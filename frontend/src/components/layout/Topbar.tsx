import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Bell, ChevronDown, LogOut, Menu } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { Avatar } from '@/components/ui/Avatar'
import { IconButton } from '@/components/ui/IconButton'
import { SearchBar } from '@/components/ui/SearchBar'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { notificationService } from '@/services/notificationService'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { NotificationItem } from '@/types/notification'
import type { Role } from '@/types/auth'

/** Only STUDENT and PLACEMENT_ADMIN/SUPER_ADMIN have a dedicated notifications page in the nav (see navConfig.ts) - FACULTY doesn't yet, so its "View all" link is simply omitted. */
function notificationsPathForRole(role: Role): string | null {
  switch (role) {
    case 'STUDENT':
      return '/student/notifications'
    case 'PLACEMENT_ADMIN':
    case 'SUPER_ADMIN':
      return '/admin/notifications'
    case 'FACULTY':
      return null
  }
}

export function Topbar({ title, onMenuClick }: { title: string; onMenuClick?: () => void }) {
  const { user, logout } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)
  // Visual only for this phase - no cross-entity global-search endpoint
  // exists on the backend yet, so this deliberately doesn't call an API
  // (per "do not invent backend endpoints"). Wire it up once a real
  // search endpoint exists.
  const [globalSearch, setGlobalSearch] = useState('')

  const [bellOpen, setBellOpen] = useState(false)
  const [unreadCount, setUnreadCount] = useState(0)
  const [recentItems, setRecentItems] = useState<NotificationItem[]>([])
  const [isLoadingRecent, setIsLoadingRecent] = useState(false)
  const bellRef = useRef<HTMLDivElement>(null)

  const role = user?.role

  useEffect(() => {
    if (!role) return
    notificationService.unreadCount(role).then(setUnreadCount)
  }, [role])

  useEffect(() => {
    if (!bellOpen || !role) return
    setIsLoadingRecent(true)
    notificationService
      .list(role, { per_page: 5 })
      .then((data) => setRecentItems(data.items))
      .finally(() => setIsLoadingRecent(false))
  }, [bellOpen, role])

  useEffect(() => {
    if (!bellOpen) return
    const onClickAway = (event: MouseEvent) => {
      if (bellRef.current && !bellRef.current.contains(event.target as Node)) setBellOpen(false)
    }
    document.addEventListener('mousedown', onClickAway)
    return () => document.removeEventListener('mousedown', onClickAway)
  }, [bellOpen])

  function handleItemClick(item: NotificationItem) {
    if (!role || item.read_at) return
    notificationService.markRead(role, item.id).then((updated) => {
      setRecentItems((prev) => prev.map((n) => (n.id === updated.id ? updated : n)))
      setUnreadCount((count) => Math.max(0, count - 1))
    })
  }

  function handleMarkAllRead() {
    if (!role) return
    notificationService.markAllRead(role).then(() => {
      setUnreadCount(0)
      setRecentItems((prev) => prev.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })))
    })
  }

  const notificationsPath = role ? notificationsPathForRole(role) : null

  return (
    <header className="flex h-16 items-center justify-between gap-3 border-b border-border bg-surface px-4 sm:px-6">
      <div className="flex min-w-0 items-center gap-3">
        <IconButton icon={Menu} label="Open navigation" onClick={onMenuClick} className="lg:hidden" />
        <h1 className="truncate text-base font-semibold text-ink sm:text-lg">{title}</h1>
      </div>

      <SearchBar
        value={globalSearch}
        onChange={setGlobalSearch}
        placeholder="Search jobs, internships, students..."
        debounceMs={0}
        className="hidden max-w-sm flex-1 md:block"
      />

      <div className="flex shrink-0 items-center gap-2 sm:gap-3">
        <div ref={bellRef} className="relative">
          <IconButton
            icon={Bell}
            label={unreadCount > 0 ? `Notifications (${unreadCount} unread)` : 'Notifications'}
            badge={unreadCount > 0}
            onClick={() => setBellOpen((open) => !open)}
          />

          {bellOpen && (
            <div className="absolute right-0 top-full z-20 mt-2 w-80 rounded-lg border border-border bg-surface py-1 shadow-lg">
              <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
                <p className="text-sm font-semibold text-ink">Notifications</p>
                {unreadCount > 0 && (
                  <button type="button" onClick={handleMarkAllRead} className="text-xs font-medium text-indigo hover:underline">
                    Mark all as read
                  </button>
                )}
              </div>

              {isLoadingRecent ? (
                <div className="p-4">
                  <LoadingSkeletonLines count={3} className="h-9 w-full" />
                </div>
              ) : recentItems.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-ink-soft">No notifications yet.</p>
              ) : (
                <ul>
                  {recentItems.map((item) => {
                    const isUnread = !item.read_at
                    return (
                      <li key={item.id}>
                        <button
                          type="button"
                          onClick={() => handleItemClick(item)}
                          className={`flex w-full items-start gap-2 px-4 py-2.5 text-left transition-colors hover:bg-sidebar ${
                            isUnread ? 'bg-indigo-tint/40' : ''
                          }`}
                        >
                          <span
                            className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${isUnread ? 'bg-gold' : 'bg-border'}`}
                            aria-hidden="true"
                          />
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-ink">{item.title}</span>
                            <span className="block text-xs text-ink-faint">{formatRelativeTime(item.created_at)}</span>
                          </span>
                        </button>
                      </li>
                    )
                  })}
                </ul>
              )}

              {notificationsPath && (
                <Link
                  to={notificationsPath}
                  onClick={() => setBellOpen(false)}
                  className="block border-t border-border px-4 py-2.5 text-center text-xs font-medium text-indigo hover:bg-sidebar"
                >
                  View all
                </Link>
              )}
            </div>
          )}
        </div>

        <div className="relative">
          <button
            type="button"
            onClick={() => setMenuOpen((open) => !open)}
            className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-sidebar"
          >
            <Avatar name={user?.name} size="sm" />
            <span className="hidden text-left sm:block">
              <span className="block text-sm font-medium text-ink">{user?.name}</span>
            </span>
            <ChevronDown className="hidden h-4 w-4 text-ink-faint sm:block" />
          </button>

          {menuOpen && (
            <div className="absolute right-0 top-full z-20 mt-2 w-44 rounded-lg border border-border bg-surface py-1 shadow-lg">
              <button
                type="button"
                onClick={() => logout()}
                className="flex w-full items-center gap-2 px-3.5 py-2 text-sm text-ink-soft hover:bg-sidebar"
              >
                <LogOut className="h-4 w-4" />
                Log out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}
