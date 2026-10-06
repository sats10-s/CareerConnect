import { useState, type FormEvent } from 'react'
import { UserPlus } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Avatar } from '@/components/ui/Avatar'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataTable } from '@/components/ui/DataTable'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { toast } from '@/components/ui/Toast'
import { ListState } from '@/components/common/ListState'
import { ApprovedGate } from '@/components/company/ApprovedGate'
import { useApiResource } from '@/hooks/useApiResource'
import { companyService } from '@/services/companyService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { CompanyMember } from '@/types/company'

function AddMemberModal({ onClose, onAdded }: { onClose: () => void; onAdded: () => void }) {
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [banner, setBanner] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }))

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBanner(null)
    const local: Record<string, string> = {}
    if (!form.name.trim()) local.name = 'Enter their name.'
    if (!form.email.trim()) local.email = 'Enter their work email.'
    if (form.password.length < 8) local.password = 'Use at least 8 characters, with upper and lower case letters and a number.'
    if (form.password !== form.password_confirmation) local.password_confirmation = 'Passwords do not match.'
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    try {
      await companyService.members.add({ ...form, name: form.name.trim(), email: form.email.trim() })
      toast('success', 'Recruiter added. Share the sign-in details with them directly.')
      onAdded()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to add this recruiter.')
      setErrors(parsed.fieldErrors)
      setBanner(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Add a recruiter"
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="add-member-form" isLoading={busy}>Add recruiter</Button>
        </>
      }
    >
      <form id="add-member-form" onSubmit={submit} noValidate className="space-y-4">
        {banner && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{banner}</div>}
        <p className="text-sm text-ink-soft">They can manage postings, applicants and interviews, but not the team.</p>
        <div>
          <label className="label" htmlFor="m-name">Name</label>
          <Input id="m-name" maxLength={150} value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name} />
        </div>
        <div>
          <label className="label" htmlFor="m-email">Work email</label>
          <Input id="m-email" type="email" maxLength={255} autoComplete="off" value={form.email} onChange={(e) => set('email', e.target.value)} error={errors.email} />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="m-pass">Temporary password</label>
            <Input id="m-pass" type="password" autoComplete="new-password" value={form.password} onChange={(e) => set('password', e.target.value)} error={errors.password} />
          </div>
          <div>
            <label className="label" htmlFor="m-pass2">Confirm password</label>
            <Input id="m-pass2" type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} error={errors.password_confirmation} />
          </div>
        </div>
      </form>
    </Modal>
  )
}

function TeamList() {
  const { data, isLoading, error, reload } = useApiResource(companyService.members.list, 'company-members')
  const [adding, setAdding] = useState(false)
  const [toggling, setToggling] = useState<CompanyMember | null>(null)
  const [busy, setBusy] = useState(false)
  const canManage = data?.can_manage ?? false

  async function toggle() {
    if (!toggling) return
    setBusy(true)
    try {
      await companyService.members.setActive(toggling.id, !toggling.is_active)
      toast('success', toggling.is_active ? `${toggling.name} was deactivated and signed out.` : `${toggling.name} can sign in again.`)
      setToggling(null)
      reload()
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to update this login.').message)
      setToggling(null)
    } finally {
      setBusy(false)
    }
  }

  const columns = [
    {
      key: 'name',
      header: 'Member',
      render: (row: CompanyMember) => (
        <div className="flex min-w-0 items-center gap-3">
          <Avatar name={row.name} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{row.name}{row.is_self && <span className="ml-1.5 text-xs font-normal text-ink-faint">(you)</span>}</p>
            <p className="truncate text-xs text-ink-faint">{row.email}</p>
          </div>
        </div>
      ),
    },
    { key: 'role', header: 'Role', render: (row: CompanyMember) => <span className="text-sm text-ink-soft">{row.member_role === 'OWNER' ? 'Owner' : 'Recruiter'}</span> },
    { key: 'seen', header: 'Last sign-in', render: (row: CompanyMember) => <span className="text-sm text-ink-soft">{row.last_login_at ? formatRelativeTime(row.last_login_at) : 'Never'}</span> },
    { key: 'status', header: 'Status', render: (row: CompanyMember) => <StatusBadge tone={row.is_active ? 'success' : 'error'}>{row.is_active ? 'Active' : 'Inactive'}</StatusBadge> },
    ...(canManage
      ? [{
          key: 'action',
          header: '',
          render: (row: CompanyMember) => row.member_role === 'OWNER' ? null : (
            <Button variant="secondary" onClick={(e) => { e.stopPropagation(); setToggling(row) }}>{row.is_active ? 'Deactivate' : 'Reactivate'}</Button>
          ),
        }]
      : []),
  ]

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-ink-soft">{canManage ? 'Add recruiters who help run hiring. Deactivating a login signs it out everywhere.' : 'Only your company’s owner can add or deactivate recruiters.'}</p>
        {canManage && <Button onClick={() => setAdding(true)}><UserPlus className="h-4 w-4" />Add recruiter</Button>}
      </div>
      <ListState isLoading={isLoading} error={error} isEmpty={(data?.items.length ?? 0) === 0} hasLoadedOnce={data !== null} onRetry={reload} empty={null}>
        <DataTable
          columns={columns}
          rows={data?.items ?? []}
          rowKey={(row) => row.id}
          renderMobileCard={(row) => (
            <div className="flex items-center justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-ink">{row.name}</p>
                <p className="truncate text-xs text-ink-faint">{row.member_role === 'OWNER' ? 'Owner' : 'Recruiter'} · {row.email}</p>
              </div>
              {canManage && row.member_role !== 'OWNER' ? (
                <Button variant="secondary" onClick={() => setToggling(row)}>{row.is_active ? 'Deactivate' : 'Reactivate'}</Button>
              ) : <StatusBadge tone={row.is_active ? 'success' : 'error'}>{row.is_active ? 'Active' : 'Inactive'}</StatusBadge>}
            </div>
          )}
        />
      </ListState>

      {adding && <AddMemberModal onClose={() => setAdding(false)} onAdded={reload} />}
      {toggling && (
        <ConfirmDialog
          open
          onClose={() => !busy && setToggling(null)}
          onConfirm={toggle}
          isConfirming={busy}
          tone={toggling.is_active ? 'danger' : 'default'}
          title={toggling.is_active ? `Deactivate ${toggling.name}?` : `Reactivate ${toggling.name}?`}
          confirmLabel={toggling.is_active ? 'Deactivate' : 'Reactivate'}
          description={toggling.is_active ? 'They are signed out immediately and can’t sign in until you reactivate them.' : 'They can sign in again with their existing password.'}
        />
      )}
    </>
  )
}

/** /company/team - the company's recruiter logins. */
export function CompanyTeamPage() {
  return (
    <div className="animate-fade-in">
      <PageHeader title="Team" description="Everyone who can sign in for your company." />
      <ApprovedGate>
        <TeamList />
      </ApprovedGate>
    </div>
  )
}
