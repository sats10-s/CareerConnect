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

export interface NotificationListMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface NotificationListData {
  items: NotificationItem[]
  meta: NotificationListMeta
}

/** The {success, message, data} envelope used by the Notification/Announcement endpoints specifically - see notificationService.ts. */
export interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T
}
