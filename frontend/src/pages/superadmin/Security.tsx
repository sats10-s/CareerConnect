import { Link } from 'react-router-dom'
import { ArrowRight, Check, ShieldCheck } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { MetricBand } from '@/components/dashboard/MetricBand'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { useApiResource } from '@/hooks/useApiResource'
import { superAdminService } from '@/services/superAdminService'
import { toneForAuditAction } from '@/utils/auditMeta'
import { formatDateTime } from '@/utils/dateFormat'
import { formatRelativeTime } from '@/utils/formatRelativeTime'

const EVENT_LABEL: Record<string, string> = {
  'auth.login': 'Signed in',
  'auth.login_failed': 'Failed sign-in',
  'auth.login_blocked': 'Blocked sign-in',
  'user.deactivated': 'Deactivated',
  'user.password_reset': 'Password reset',
  'user.sessions_revoked': 'Signed out everywhere',
}

function SectionLink({ to, children }: { to: string; children: string }) {
  return (
    <Link to={to} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
      {children}
      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
    </Link>
  )
}

/**
 * Super Admin > Security (GET /super-admin/security): sign-in activity and
 * account health from the audit log, users and session/token tables. The
 * actions it points to (deactivate, reset password, sign out everywhere)
 * live on each account in Users.
 */
export function SuperAdminSecurityPage() {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.security(), 'super-admin-security')

  if (isLoading && !data) return <DashboardLoading stats={4} />
  if (error || !data) return <DashboardError message={error} onRetry={reload} />

  const { stats } = data
  const health = [
    { label: 'Inactive accounts', value: stats.inactive_accounts },
    { label: 'Never signed in', value: stats.never_signed_in },
    { label: 'Signed-in devices (API tokens)', value: stats.api_tokens },
    ...(stats.web_sessions !== null ? [{ label: 'Active web sessions', value: stats.web_sessions }] : []),
  ]

  return (
    <div className="animate-fade-in space-y-8">
      <PageHeader title="Security" description="Sign-in activity, suspicious attempts and account health." />

      <section className="space-y-3">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Sign-ins</h2>
        <MetricBand
          metrics={[
            { label: 'Successful · 24h', value: stats.successful_logins_24h },
            { label: 'Failed · 24h', value: stats.failed_logins_24h },
            { label: 'Failed · 7 days', value: stats.failed_logins_7d },
            { label: 'Blocked (inactive) · 7 days', value: stats.blocked_logins_7d },
          ]}
        />
      </section>

      <section className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Accounts</h2>
          {stats.inactive_accounts > 0 && <SectionLink to="/super-admin/users?status=inactive">Review inactive accounts</SectionLink>}
        </div>
        <MetricBand metrics={health} />
      </section>

      <div className="grid grid-cols-1 gap-8 xl:grid-cols-2">
        <section>
          <SectionHeader title="Failed sign-ins by account" subtitle="Last 7 days, most attempts first" />
          {data.failed_by_account.length === 0 ? (
            <div className="flex items-center gap-2 py-6 text-sm text-ink-soft">
              <ShieldCheck className="h-4 w-4 text-success" aria-hidden="true" />
              No failed sign-ins in the last 7 days.
            </div>
          ) : (
            <ul className="divide-y divide-hairline">
              {data.failed_by_account.map((row) => (
                <li key={row.email} className="flex items-start justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">{row.email}</p>
                    <p className="truncate text-xs text-ink-faint">
                      {row.last_attempt_at ? `Last ${formatRelativeTime(row.last_attempt_at)}` : ''}
                      {row.ip_addresses.length ? ` · ${row.ip_addresses.join(', ')}` : ''}
                    </p>
                  </div>
                  <StatusBadge tone={row.attempts >= 5 ? 'error' : 'warning'}>{row.attempts} attempt{row.attempts === 1 ? '' : 's'}</StatusBadge>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section>
          <SectionHeader title="Recent security events" action={<SectionLink to="/super-admin/audit-logs?area=auth">Full audit log</SectionLink>} />
          {data.recent_events.length === 0 ? (
            <p className="py-6 text-sm text-ink-soft">No sign-in or account-security events recorded yet.</p>
          ) : (
            <ul className="divide-y divide-hairline">
              {data.recent_events.map((e) => (
                <li key={e.id} className="flex items-start justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="text-sm text-ink">{e.description}</p>
                    <p className="text-xs text-ink-faint">
                      {e.created_at && <time dateTime={e.created_at} title={formatDateTime(e.created_at)}>{formatRelativeTime(e.created_at)}</time>}
                      {e.ip_address ? ` · ${e.ip_address}` : ''}
                    </p>
                  </div>
                  <StatusBadge tone={toneForAuditAction(e.action)}>{EVENT_LABEL[e.action] ?? e.action}</StatusBadge>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="card p-5">
        <h2 className="text-sm font-semibold text-ink">Sign-in policy</h2>
        <ul className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
          {[
            ...data.policy.password_rules.map((r) => `Passwords: ${r.charAt(0).toLowerCase()}${r.slice(1)}`),
            `Web sessions expire after ${data.policy.session_lifetime_minutes} minutes of inactivity`,
            ...(data.policy.deactivation_signs_out ? ['Deactivating an account signs it out of every device immediately'] : []),
            'Deactivated accounts are refused at sign-in, and each attempt is logged',
          ].map((rule) => (
            <li key={rule} className="flex gap-2 text-sm text-ink-soft">
              <Check className="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
              {rule}
            </li>
          ))}
        </ul>
        <p className="mt-3 text-xs text-ink-faint">These rules are enforced by the server. Per-account actions (reset password, sign out everywhere, deactivate) are on each account in Users.</p>
      </section>
    </div>
  )
}
