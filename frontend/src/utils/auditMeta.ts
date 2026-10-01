import type { StatusTone } from '@/components/ui/StatusBadge'

export const AUDIT_AREAS = [
  { value: 'auth', label: 'Sign-ins' },
  { value: 'user', label: 'Accounts' },
  { value: 'department', label: 'Departments' },
  { value: 'batch', label: 'Batches' },
  { value: 'company', label: 'Companies' },
  { value: 'settings', label: 'Settings' },
] as const

/** Badge tone for an audit action key such as "auth.login_failed". */
export function toneForAuditAction(action: string): StatusTone {
  if (/login_failed|blocked/.test(action)) return 'error'
  if (/deactivated|deleted|sessions_revoked|password_reset/.test(action)) return 'warning'
  if (/auth\.login$|activated|created|registered/.test(action)) return 'success'
  return 'info'
}
