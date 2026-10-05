import { useEffect, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { LogIn } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { superAdminService } from '@/services/superAdminService'
import { dashboardPathForRole } from '@/utils/roles'
import { parseApiError } from '@/utils/apiError'

/**
 * Students sign in with their JNTU No (first time: the mobile number the
 * college has on record, then they must choose their own password); staff
 * sign in with their email.
 */
export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [registrationOpen, setRegistrationOpen] = useState(false)

  useEffect(() => {
    superAdminService.publicSettings().then((s) => setRegistrationOpen(s.allow_student_registration)).catch(() => setRegistrationOpen(false))
  }, [])

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)
    try {
      const user = await login({ login: identifier.trim(), password })
      if (user.must_change_password) {
        navigate('/first-login', { replace: true })
        return
      }
      const redirectTo = (location.state as { from?: string } | null)?.from
      navigate(redirectTo ?? dashboardPathForRole(user.role), { replace: true })
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to sign in. Please try again.')
      // 422 (wrong credentials) and 429 (locked / throttled) carry the real reason.
      setError(parsed.fieldErrors.login ?? parsed.fieldErrors.email ?? (parsed.status === 429 ? 'Too many attempts. Please wait a few minutes and try again.' : parsed.message))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div>
      <h1 className="font-display font-bold tracking-tight text-3xl text-ink">Welcome Back</h1>
      <p className="mt-1.5 text-sm text-muted">Sign in to your account</p>

      <form onSubmit={handleSubmit} className="mt-8 space-y-5">
        {error && (
          <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>
        )}

        <div>
          <label htmlFor="login" className="label">JNTU No or email</label>
          <input
            id="login"
            type="text"
            required
            autoComplete="username"
            autoCapitalize="characters"
            spellCheck={false}
            className="input"
            placeholder="23341A0501 or you@careerconnect.edu"
            value={identifier}
            onChange={(e) => setIdentifier(e.target.value)}
          />
        </div>

        <div>
          <div className="mb-1.5 flex items-center justify-between">
            <label htmlFor="password" className="label mb-0">Password</label>
            <Link to="/forgot-password" className="text-xs font-medium text-primary hover:text-primary-hov">
              Forgot password?
            </Link>
          </div>
          <input
            id="password"
            type="password"
            required
            autoComplete="current-password"
            className="input"
            placeholder="••••••••"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </div>

        <p className="rounded-lg bg-primary-tint px-3.5 py-2.5 text-xs text-ink-soft">
          First time? Use your JNTU number and the mobile number registered with the college.
        </p>

        <Button type="submit" isLoading={isSubmitting} className="w-full">
          <LogIn className="h-4 w-4" />
          Sign in
        </Button>
      </form>

      {registrationOpen && (
        <p className="mt-6 text-center text-sm text-muted">
          New student?{' '}
          <Link to="/register" className="font-semibold text-primary hover:text-primary-hov">
            Create an account
          </Link>
        </p>
      )}
    </div>
  )
}
