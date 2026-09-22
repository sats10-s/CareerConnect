import type { Role } from '@/types/auth'

/**
 * Mirrors App\Enums\RoleName::dashboardPath() on the backend - kept
 * as a single source of truth here for the SPA so login/redirect
 * logic never has to duplicate this mapping.
 */
export const ROLE_DASHBOARD_PATH: Record<Role, string> = {
  STUDENT: '/student/dashboard',
  FACULTY: '/faculty/dashboard',
  PLACEMENT_ADMIN: '/admin/dashboard',
  SUPER_ADMIN: '/super-admin/dashboard',
}

export const ROLE_LABEL: Record<Role, string> = {
  STUDENT: 'Student',
  FACULTY: 'Faculty',
  PLACEMENT_ADMIN: 'Placement Admin',
  SUPER_ADMIN: 'Super Admin',
}

export function dashboardPathForRole(role: Role): string {
  return ROLE_DASHBOARD_PATH[role]
}
