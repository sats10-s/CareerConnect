import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { authService } from '@/services/authService'
import { parseApiError } from '@/utils/apiError'

/** The page the emailed reset link opens (/reset-password?token=...&login=...). */
export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const login = params.get('login') ?? ''
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [done, setDone] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    const local: Record<string, string> = {}
    if (password.length < 8) local.password = 'Use at least 8 characters.'
    if (confirmation !== password) local.password_confirmation = 'The two passwords do not match.'
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    try {
      setDone(await authService.resetPassword({ login, token, password, password_confirmation: confirmation }))
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to reset your password.')
      setErrors(Object.keys(parsed.fieldErrors).length ? parsed.fieldErrors : { form: parsed.message })
    } finally {
      setBusy(false)
    }
  }

  if (!token || !login) {
    return (
      <div className="text-center">
        <h1 className="font-display text-2xl font-bold text-ink">Link incomplete</h1>
        <p className="mt-2 text-sm text-muted">Open the link from your email again, or request a new one.</p>
        <Link to="/forgot-password" className="mt-6 inline-block text-sm font-semibold text-primary">Request a new link</Link>
      </div>
    )
  }

  if (done) {
    return (
      <div className="text-center">
        <span className="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-success-bg text-success"><CheckCircle2 className="h-5 w-5" /></span>
        <h1 className="mt-4 font-display text-2xl font-bold text-ink">Password changed</h1>
        <p className="mt-2 text-sm text-muted">{done}</p>
        <Link to="/login" className="mt-6 inline-block text-sm font-semibold text-primary hover:text-primary-hov">Sign in</Link>
      </div>
    )
  }

  return (
    <div>
      <h1 className="font-display text-2xl font-bold tracking-tight text-ink">Choose a new password</h1>
      <p className="mt-1.5 text-sm text-muted">For {login}.</p>
      <form onSubmit={submit} className="mt-7 space-y-4" noValidate>
        {(errors.token || errors.form) && (
          <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">
            {errors.token ?? errors.form}{' '}
            {errors.token && <Link to="/forgot-password" className="font-semibold underline">Request a new link</Link>}
          </div>
        )}
        <div>
          <label className="label" htmlFor="reset-password">New password</label>
          <Input id="reset-password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} />
        </div>
        <div>
          <label className="label" htmlFor="reset-confirm">Confirm password</label>
          <Input id="reset-confirm" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} error={errors.password_confirmation} />
        </div>
        <Button type="submit" isLoading={busy} className="w-full">Change password</Button>
      </form>
    </div>
  )
}
