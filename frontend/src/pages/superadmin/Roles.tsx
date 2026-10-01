import { Link } from 'react-router-dom'
import { ArrowRight, Check } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { useApiResource } from '@/hooks/useApiResource'
import { superAdminService } from '@/services/superAdminService'
import { ROLE_ICON } from '@/utils/navConfig'

/**
 * Super Admin > Roles (GET /super-admin/roles). The four roles and what
 * each can do are defined in code (App\Enums\RoleName + route middleware),
 * so this page explains them and shows who holds each; people are moved
 * between admin roles from Users.
 */
export function SuperAdminRolesPage() {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.roles(), 'super-admin-roles')

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="Roles" description="What each role can do and how many people hold it." />

      {isLoading && !data ? (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2" aria-busy="true">
          {[0, 1, 2, 3].map((i) => <LoadingSkeleton key={i} className="h-56 w-full rounded-xl" />)}
        </div>
      ) : error || !data ? (
        <ErrorState description={error ?? undefined} onRetry={reload} />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {data.map((role) => {
              const Icon = ROLE_ICON[role.name]
              const inactive = role.users_count - role.active_count
              return (
                <article key={role.name} className="card flex flex-col p-5">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-tint text-indigo">
                        <Icon className="h-5 w-5" aria-hidden="true" />
                      </span>
                      <div>
                        <h2 className="font-display text-base font-semibold text-ink">{role.label}</h2>
                        <p className="text-xs text-ink-faint">{role.name}</p>
                      </div>
                    </div>
                    <div className="text-right">
                      <p className="font-display text-2xl font-bold tabular-nums text-ink">{role.users_count}</p>
                      <p className="text-xs text-ink-faint">{inactive > 0 ? `${role.active_count} active · ${inactive} inactive` : 'all active'}</p>
                    </div>
                  </div>
                  <ul className="mt-4 flex-1 space-y-2">
                    {role.capabilities.map((c) => (
                      <li key={c} className="flex gap-2 text-sm text-ink-soft">
                        <Check className="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                        <span>{c}</span>
                      </li>
                    ))}
                  </ul>
                  <Link to={`/super-admin/users?role=${role.name}`} className="mt-4 inline-flex items-center gap-1 self-start text-sm font-medium text-primary hover:underline">
                    View {role.label.toLowerCase()} accounts
                    <ArrowRight className="h-4 w-4" aria-hidden="true" />
                  </Link>
                </article>
              )
            })}
          </div>
          <p className="text-xs text-ink-faint">
            Roles and their permissions are fixed by the platform. Placement Admins can be promoted to Super Admin (and back) from Users; students and faculty keep their role because their records depend on it.
          </p>
        </>
      )}
    </div>
  )
}
