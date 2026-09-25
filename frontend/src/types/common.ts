/**
 * Shared shapes reused across every paginated list endpoint this
 * codebase's newer controllers return (Notifications, Applications,
 * and every list this build-out adds after it) - all follow the same
 * `{items, meta: {current_page, per_page, total, last_page}}` shape
 * from Laravel's LengthAwarePaginator, wrapped in the
 * {success, message, data} envelope those controllers use.
 */
export interface PaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface PaginatedList<T> {
  items: T[]
  meta: PaginationMeta
}

export interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T
}
