import type { ApiEnvelope, PaginatedList } from './common'

/**
 * Mirrors App\Http\Resources\NotificationResource - the single
 * representation used by every notification endpoint (student,
 * faculty, admin). `type` is left as `string` rather than a literal
 * union of the 15 NotificationType enum values: the frontend never
 * branches on it beyond a display label, so a new backend case never
 * needs a frontend type change to keep compiling.
 */
export interface NotificationItem {
  id: number
  type: string
  title: string
  message: string
  data: Record<string, unknown>
  read_at: string | null
  created_at: string
}

/** @deprecated use PaginationMeta from ./common - kept as an alias so nothing importing this name breaks. */
export type NotificationListMeta = PaginatedList<NotificationItem>['meta']

export type NotificationListData = PaginatedList<NotificationItem>

export type { ApiEnvelope }
