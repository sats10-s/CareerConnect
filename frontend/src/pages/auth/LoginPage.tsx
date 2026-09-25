import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { LogIn } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { dashboardPathForRole } from '@/utils/roles'
import type { ApiValidationError } from '@/types/auth'
import { isAxiosError } from 'axios'

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [showForgotNotice, setShowForgotNotice] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)
    try {
      const user = await login({ email, password })
      const redirectTo = (location.state as { from?: string } | null)?.from
      navigate(redirectTo ?? dashboardPathForRole(user.role), { replace: true })
    } catch (err) {
      // Surface what actually happened instead of a generic message -
      // a 419/422/500 with a real backend message is a different
      // problem than the browser never getting a response at all
      // (CORS/network failure), and hiding that distinction is what
      // made this bug hard to diagnose from the UI alone.
      if (isAxiosError<ApiValidationError>(err)) {
        if (err.response) {
          setError(`Login failed (${err.response.status}): ${err.response.data?.message ?? 'No error message returned.'}`)
        } else if (err.request) {
          setError('Login failed: no response from the server. This usually means a CORS rejection or the backend is unreachable - check the browser console/Network tab.')
        } else {
          setError(`Login failed: ${err.message}`)
        }
      } else if (import.meta.env.DEV && err instanceof Error) {
        // A non-axios error means something threw outside the HTTP
        // request itself (e.g. in the post-login redirect logic) -
        // show it plainly in dev rather than hiding it behind the
        // generic copy, since that's exactly the case the generic
        // message was masking.
        setError(`Unexpected error (not an HTTP failure): ${err.name}: ${err.message}`)
      } else {
        setError('Unable to log in. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div>
      <h1 className="font-extrabold tracking-tight text-3xl text-ink">Welcome Back</h1>
      <p className="mt-1.5 text-sm text-muted">Sign in to your account</p>

      <form onSubmit={handleSubmit} className="mt-6 space-y-4">
        {error && (
          <div className="rounded-lg border border-error/20 bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>
        )}

        <div>
          <label htmlFor="email" className="label">College email</label>
          <input
            id="email"
            type="email"
            required
            autoComplete="email"
            className="input"
            placeholder="you@careerconnect.edu"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </div>

        <div>
          <div className="mb-1.5 flex items-center justify-between">
            <label htmlFor="password" className="label mb-0">Password</label>
            <button
              type="button"
              onClick={() => setShowForgotNotice((open) => !open)}
              className="text-xs font-medium text-primary hover:text-primary-hov"
            >
              Forgot password?
            </button>
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
          {showForgotNotice && (
            <p className="mt-1.5 text-xs text-muted">
              Password resets aren't self-service yet - contact your placement office to reset your password.
            </p>
          )}
        </div>

        <Button type="submit" isLoading={isSubmitting} className="w-full">
          <LogIn className="h-4 w-4" />
          Sign in
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-muted">
        New student?{' '}
        <Link to="/register" className="font-semibold text-primary hover:text-primary-hov">
          Create an account
        </Link>
      </p>
    </div>
  )
}
