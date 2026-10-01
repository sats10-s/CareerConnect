import { useState, type FormEvent } from 'react'
import { KeyRound, LogOut, Pencil, Power } from 'lucide-react'
import { Avatar } from '@/components/ui/Avatar'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { Input } from '@/components/ui/Input'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Modal } from '@/components/ui/Modal'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { toast } from '@/components/ui/Toast'
import { DetailItem } from '@/components/common/Cells'
import { PASSWORD_HINT, UserFormModal } from './UserFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { superAdminService } from '@/services/superAdminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import type { ManagedUser } from '@/types/superAdmin'

function ResetPasswordModal({ user, onClose }: { user: ManagedUser; onClose: () => void }) {
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (password.length < 8) return setErrors({ password: PASSWORD_HINT })
    if (password !== confirm) return setErrors({ password_confirmation: "The passwords don't match." })
    setErrors({})
    setBusy(true)
    try {
      const message = await superAdminService.users.resetPassword(user.id, password, confirm)
      toast('success', message || 'Password reset.')
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to reset the password.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`Reset password for ${user.name}`}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="reset-form" isLoading={busy}>Reset password</Button>
        </>
      }
    >
      <form id="reset-form" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <p className="text-sm text-ink-soft">
          {user.is_self ? 'Your other devices stay signed in.' : `${user.name} is signed out of every device and must use the new password.`}
        </p>
        <div>
          <label className="label" htmlFor="rp-pass">New password</label>
          <Input id="rp-pass" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} autoFocus />
        </div>
        <div>
          <label className="label" htmlFor="rp-pass2">Confirm new password</label>
          <Input id="rp-pass2" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} error={errors.password_confirmation} />
        </div>
        <p className="text-xs text-ink-faint">{PASSWORD_HINT}</p>
      </form>
    </Modal>
  )
}

type Pending = 'status' | 'sessions' | null

/** GET /super-admin/users/{id}: account details plus edit, activate/deactivate, reset password and sign out everywhere. */
export function UserDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.users.show(id as number), id)
  const [editing, setEditing] = useState(false)
  const [resetting, setResetting] = useState(false)
  const [confirming, setConfirming] = useState<Pending>(null)
  const [busy, setBusy] = useState(false)

  const changed = () => {
    reload()
    onChanged()
  }

  async function runConfirmed() {
    if (!data || !confirming) return
    setBusy(true)
    try {
      if (confirming === 'status') {
        const updated = await superAdminService.users.setActive(data.id, !data.is_active)
        toast('success', updated.is_active ? `${updated.name} can sign in again.` : `${updated.name} is deactivated and signed out.`)
      } else {
        const { revoked } = await superAdminService.users.revokeSessions(data.id)
        toast('success', revoked ? `Signed ${data.name} out of ${revoked} session${revoked === 1 ? '' : 's'}.` : `${data.name} had no active sessions.`)
      }
      setConfirming(null)
      changed()
    } catch (err) {
      toast('error', parseApiError(err, 'That action failed.').message)
    } finally {
      setBusy(false)
    }
  }

  const locked = data?.is_self

  return (
    <>
      <Drawer
        open={id !== null}
        onClose={onClose}
        title={data?.name ?? 'Account'}
        width="md"
        footer={data ? (
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            <Button variant="secondary" onClick={() => setEditing(true)}>
              <Pencil className="h-4 w-4" />
              Edit details
            </Button>
            {!locked && (
              <Button variant={data.is_active ? 'danger' : 'primary'} onClick={() => setConfirming('status')}>
                <Power className="h-4 w-4" />
                {data.is_active ? 'Deactivate' : 'Activate'}
              </Button>
            )}
          </div>
        ) : undefined}
      >
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-12 w-2/3" />
            <LoadingSkeleton className="h-32 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : data ? (
          <div className="space-y-6">
            <div className="flex items-center gap-3">
              <Avatar name={data.name} size="lg" />
              <div className="min-w-0">
                <p className="truncate text-base font-semibold text-ink">{data.name}{data.is_self && <span className="ml-2 text-xs font-medium text-ink-faint">(you)</span>}</p>
                <p className="truncate text-sm text-ink-soft">{data.email}</p>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                  <StatusBadge tone="info">{data.role_label}</StatusBadge>
                  <StatusBadge tone={data.is_active ? 'success' : 'error'}>{data.is_active ? 'Active' : 'Inactive'}</StatusBadge>
                </div>
              </div>
            </div>

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Last sign-in">{data.last_login_at ? formatDateTime(data.last_login_at) : 'Never'}</DetailItem>
              <DetailItem label="Created">{formatDate(data.created_at)}</DetailItem>
              <DetailItem label="Signed-in devices">{String(data.tokens_count ?? 0)}</DetailItem>
              {data.student && <DetailItem label="Roll number">{data.student.roll_number}</DetailItem>}
              {data.student && <DetailItem label="Department">{data.student.department}</DetailItem>}
              {data.student && <DetailItem label="Batch · Year">{[data.student.batch, data.student.year ? `Year ${data.student.year}` : null].filter(Boolean).join(' · ')}</DetailItem>}
              {data.faculty && <DetailItem label="Employee code">{data.faculty.employee_code}</DetailItem>}
              {data.faculty && <DetailItem label="Department">{data.faculty.department}</DetailItem>}
              {data.faculty && <DetailItem label="Designation">{data.faculty.designation}</DetailItem>}
              {data.faculty && <DetailItem label="Phone">{data.faculty.phone}</DetailItem>}
            </dl>

            <section className="space-y-3 rounded-xl border border-hairline p-4">
              <h4 className="text-sm font-semibold text-ink">Access</h4>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="min-w-0 flex-1 basis-56 text-sm text-ink-soft">
                  {data.is_self ? 'Set a new password for your account.' : 'Set a new password. They are signed out everywhere and must use it next time.'}
                </p>
                <Button variant="secondary" onClick={() => setResetting(true)}>
                  <KeyRound className="h-4 w-4" />
                  Reset password
                </Button>
              </div>
              {!locked && data.is_active && (
                <div className="flex flex-wrap items-start justify-between gap-3 border-t border-hairline pt-3">
                  <p className="min-w-0 flex-1 basis-56 text-sm text-ink-soft">End every session and API token, e.g. if a device is lost or a password may be known to someone else.</p>
                  <Button variant="secondary" onClick={() => setConfirming('sessions')}>
                    <LogOut className="h-4 w-4" />
                    Sign out everywhere
                  </Button>
                </div>
              )}
            </section>
            {locked && <p className="text-xs text-ink-faint">This is your account, so it can't be deactivated or signed out everywhere from here.</p>}
          </div>
        ) : null}
      </Drawer>

      {editing && data && <UserFormModal existing={data} onClose={() => setEditing(false)} onSaved={changed} />}
      {resetting && data && <ResetPasswordModal user={data} onClose={() => { setResetting(false); changed() }} />}
      {data && (
        <ConfirmDialog
          open={confirming !== null}
          onClose={() => setConfirming(null)}
          onConfirm={runConfirmed}
          isConfirming={busy}
          tone={confirming === 'status' && data.is_active ? 'danger' : 'default'}
          title={confirming === 'sessions' ? `Sign ${data.name} out everywhere?` : data.is_active ? `Deactivate ${data.name}?` : `Activate ${data.name}?`}
          confirmLabel={confirming === 'sessions' ? 'Sign out' : data.is_active ? 'Deactivate' : 'Activate'}
          description={
            confirming === 'sessions'
              ? 'They will need to sign in again on every device.'
              : data.is_active
                ? "They are signed out immediately and can't sign in until reactivated. Their records are kept."
                : 'They will be able to sign in again with their existing password.'
          }
        />
      )}
    </>
  )
}
