import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { MailCheck } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { authService } from '@/services/authService'
import { parseApiError } from '@/utils/apiError'

/** Request a reset link - sent to the personal email a student added at first sign-in. */
export function ForgotPasswordPage() {
  const [login, setLogin] = useState('')
  const [sent, setSent] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!login.trim()) return setError('Enter your JNTU No or email.')
    setBusy(true)
    setError(null)
    try {
      setSent(await authService.forgotPassword(login.trim()))
    } catch (err) {
      setError(parseApiError(err, 'Unable to send a reset link right now.').message)
    } finally {
      setBusy(false)
    }
  }

  if (sent) {
    return (
      <div className="text-center">
        <span className="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-primary-tint text-primary"><MailCheck className="h-5 w-5" /></span>
        <h1 className="mt-4 font-display text-2xl font-bold text-ink">Check your email</h1>
        <p className="mt-2 text-sm text-muted">{sent}</p>
        <p className="mt-4 text-xs text-ink-faint">No personal email on file yet? Ask the placement office to reset your password.</p>
        <Link to="/login" className="mt-6 inline-block text-sm font-semibold text-primary hover:text-primary-hov">Back to sign in</Link>
      </div>
    )
  }

  return (
    <div>
      <h1 className="font-display text-2xl font-bold tracking-tight text-ink">Forgot your password?</h1>
      <p className="mt-1.5 text-sm text-muted">Enter your JNTU No (or staff email). We'll email a reset link to the personal email on your account.</p>
      <form onSubmit={submit} className="mt-7 space-y-4">
        <div>
          <label className="label" htmlFor="forgot-login">JNTU No or email</label>
          <Input id="forgot-login" autoComplete="username" spellCheck={false} value={login} onChange={(e) => setLogin(e.target.value)} error={error ?? undefined} />
        </div>
        <Button type="submit" isLoading={busy} className="w-full">Send reset link</Button>
      </form>
      <Link to="/login" className="mt-6 block text-center text-sm font-semibold text-primary hover:text-primary-hov">Back to sign in</Link>
    </div>
  )
}
