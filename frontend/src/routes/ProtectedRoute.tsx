import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { Spinner } from '@/components/ui/Spinner'
import { dashboardPathForRole } from '@/utils/roles'
import type { Role } from '@/types/auth'

/**
 * Client-side route guard. This ONLY controls what the SPA renders -
 * every API endpoint it calls is independently protected by Sanctum
 * auth + the `role` middleware on the backend, so a user can never
 * see real data just by tricking the frontend router.
 */
export function ProtectedRoute({ allowedRoles }: { allowedRoles: Role[] }) {
  const { user, isAuthenticated, isLoading } = useAuth()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-base">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (!isAuthenticated || !user) {
    return <Navigate to="/login" replace />
  }

  // Still on a first-time password: the backend answers 403 to everything
  // else, so send them to set their own password first.
  if (user.must_change_password) {
    return <Navigate to="/first-login" replace />
  }

  if (!allowedRoles.includes(user.role)) {
    return <Navigate to={dashboardPathForRole(user.role)} replace />
  }

  return <Outlet />
}
