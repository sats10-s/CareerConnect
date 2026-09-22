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
      } else {
        setError('Unable to log in. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div>
      <h2 className="text-lg font-semibold text-gray-900">Sign in to your account</h2>
      <p className="mt-1 text-sm text-gray-500">Use your college email and password.</p>

      <form onSubmit={handleSubmit} className="mt-6 space-y-4">
        {error && (
          <div className="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{error}</div>
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
          <label htmlFor="password" className="label">Password</label>
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

        <Button type="submit" isLoading={isSubmitting} className="w-full">
          <LogIn className="h-4 w-4" />
          Sign in
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-gray-500">
        New student?{' '}
        <Link to="/register" className="font-semibold text-navy-700 hover:text-navy-800">
          Create an account
        </Link>
      </p>
    </div>
  )
}
