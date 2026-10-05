import { api } from './api'
import type { Role } from '@/types/auth'
import type { ApiEnvelope, NotificationItem, NotificationListData } from '@/types/notification'

/**
 * Notification routes are mounted per role prefix (student/faculty/
 * admin - see routes/api.php), with SUPER_ADMIN sharing the `admin`
 * prefix (its middleware is `role:PLACEMENT_ADMIN,SUPER_ADMIN`, the
 * same as PLACEMENT_ADMIN's). There is no separate super-admin
 * notification route.
 */
function prefixForRole(role: Role): 'student' | 'faculty' | 'admin' | 'company' {
  switch (role) {
    case 'STUDENT':
      return 'student'
    case 'FACULTY':
      return 'faculty'
    case 'PLACEMENT_ADMIN':
    case 'SUPER_ADMIN':
      return 'admin'
    case 'COMPANY':
      return 'company'
  }
}

export interface NotificationListParams {
  status?: 'read' | 'unread'
  per_page?: number
  page?: number
}

/**
 * Unlike dashboardService (which calls unwrapped endpoints),
 * every notification endpoint uses the {success, message, data}
 * envelope (Api\{Student,Faculty,Admin}\NotificationController uses
 * FormatsApiResponse) - each call here unwraps `.data.data`.
 */
export const notificationService = {
  list: (role: Role, params: NotificationListParams = {}) =>
    api
      .get<ApiEnvelope<NotificationListData>>(`/${prefixForRole(role)}/notifications`, { params })
      .then((r) => r.data.data),

  unreadCount: (role: Role) =>
    api
      .get<ApiEnvelope<{ unread_count: number }>>(`/${prefixForRole(role)}/notifications/unread-count`)
      .then((r) => r.data.data.unread_count),

  markRead: (role: Role, id: number) =>
    api
      .patch<ApiEnvelope<NotificationItem>>(`/${prefixForRole(role)}/notifications/${id}/read`)
      .then((r) => r.data.data),

  markAllRead: (role: Role) =>
    api
      .post<ApiEnvelope<{ updated: number }>>(`/${prefixForRole(role)}/notifications/read-all`)
      .then((r) => r.data.data.updated),
}
