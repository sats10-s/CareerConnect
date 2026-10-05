import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { KeyRound, LogOut } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Spinner } from '@/components/ui/Spinner'
import { dashboardPathForRole } from '@/utils/roles'
import { formBanner, parseApiError } from '@/utils/apiError'

/**
 * The forced first-login step for anyone still on a first-time password
 * (their mobile number, or a one-time password): choose a password and, for
 * students, add a personal email for future password resets. Every other
 * page answers 403 until this is done.
 */
export function FirstLoginPage() {
  const { user, isLoading, completeFirstLogin, logout } = useAuth()
  const navigate = useNavigate()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [personalEmail, setPersonalEmail] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [banner, setBanner] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (isLoading) {
    return <div className="flex justify-center py-10"><Spinner className="h-6 w-6" /></div>
  }
  if (!user) return <Navigate to="/login" replace />
  if (!user.must_change_password) return <Navigate to={dashboardPathForRole(user.role)} replace />

  const isStudent = user.role === 'STUDENT'
  const roll = user.student?.roll_number ?? ''
  const mobile = user.student?.phone ?? ''

  async function submit(event: FormEvent) {
    event.preventDefault()
    const local: Record<string, string> = {}
    if (password.length < 8) local.password = 'Use at least 8 characters.'
    else if (password === mobile || (roll && password.toUpperCase() === roll.toUpperCase())) local.password = 'Choose a password that is not your mobile number or JNTU No.'
    if (confirmation !== password) local.password_confirmation = 'The two passwords do not match.'
    if (isStudent && !/^\S+@\S+\.\S+$/.test(personalEmail.trim())) local.personal_email = 'Enter your personal email - password reset links go there.'
    setErrors(local)
    setBanner(null)
    if (Object.keys(local).length) return

    setBusy(true)
    try {
      const updated = await completeFirstLogin({ password, password_confirmation: confirmation, ...(isStudent ? { personal_email: personalEmail.trim() } : {}) })
      navigate(dashboardPathForRole(updated.role), { replace: true })
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save your password.')
      setErrors(parsed.fieldErrors)
      setBanner(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-tint text-primary">
        <KeyRound className="h-5 w-5" />
      </span>
      <h1 className="mt-4 font-display text-2xl font-bold tracking-tight text-ink">Set your password</h1>
      <p className="mt-1.5 text-sm text-muted">
        {isStudent ? `Welcome, ${user.name}. Choose your own password before you continue - your mobile number stops working as a password once you do.` : 'Choose a new password before you continue.'}
      </p>

      <form onSubmit={submit} className="mt-7 space-y-4" noValidate>
        {banner && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{banner}</div>}

        <div>
          <label className="label" htmlFor="new-password">New password</label>
          <Input id="new-password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} />
          {!errors.password && <p className="mt-1 text-xs text-ink-faint">At least 8 characters, and not your mobile number or JNTU No.</p>}
        </div>
        <div>
          <label className="label" htmlFor="confirm-password">Confirm password</label>
          <Input id="confirm-password" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} error={errors.password_confirmation} />
        </div>
        {isStudent && (
          <div>
            <label className="label" htmlFor="personal-email">Personal email</label>
            <Input id="personal-email" type="email" autoComplete="email" placeholder="you@gmail.com" value={personalEmail} onChange={(e) => setPersonalEmail(e.target.value)} error={errors.personal_email} />
            {!errors.personal_email && <p className="mt-1 text-xs text-ink-faint">Used to send you a reset link if you forget your password.</p>}
          </div>
        )}

        <Button type="submit" isLoading={busy} className="w-full">Save and continue</Button>
      </form>

      <button type="button" onClick={() => logout().then(() => navigate('/login', { replace: true }))} className="mx-auto mt-6 flex items-center gap-1.5 text-sm text-muted hover:text-ink">
        <LogOut className="h-4 w-4" /> Sign out
      </button>
    </div>
  )
}
