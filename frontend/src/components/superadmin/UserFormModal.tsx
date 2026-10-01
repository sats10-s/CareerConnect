import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { toast } from '@/components/ui/Toast'
import { useApiResource } from '@/hooks/useApiResource'
import { metaService } from '@/services/metaService'
import { superAdminService } from '@/services/superAdminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import type { CreateUserPayload, ManagedUser, StaffRole, UpdateUserPayload } from '@/types/superAdmin'

const STAFF_ROLES: { value: StaffRole; label: string; hint: string }[] = [
  { value: 'FACULTY', label: 'Faculty', hint: 'Coordinates assigned interns: attendance, reports, completion.' },
  { value: 'PLACEMENT_ADMIN', label: 'Placement Admin', hint: 'Runs jobs, internships, drives, interviews and applications.' },
  { value: 'SUPER_ADMIN', label: 'Super Admin', hint: 'Full access, including accounts, settings and the audit log.' },
]

export const PASSWORD_HINT = 'At least 8 characters with upper- and lower-case letters and a number.'

/**
 * Add a staff account (POST /super-admin/users) or edit one (PATCH). Students
 * register themselves, so they can't be created here. Faculty need an
 * employee code and department; role changes are limited to the two admin
 * roles because student/faculty records depend on theirs.
 */
export function UserFormModal({ existing, onClose, onSaved }: { existing?: ManagedUser; onClose: () => void; onSaved: (user: ManagedUser) => void }) {
  const editing = Boolean(existing)
  const [name, setName] = useState(existing?.name ?? '')
  const [email, setEmail] = useState(existing?.email ?? '')
  const [role, setRole] = useState<StaffRole>((existing?.role as StaffRole) ?? 'FACULTY')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [employeeCode, setEmployeeCode] = useState(existing?.faculty?.employee_code ?? '')
  const [departmentId, setDepartmentId] = useState(existing?.faculty?.department_id ? String(existing.faculty.department_id) : '')
  const [designation, setDesignation] = useState(existing?.faculty?.designation ?? '')
  const [phone, setPhone] = useState(existing?.faculty?.phone ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const departments = useApiResource(() => metaService.departments(), 'departments')

  const isFaculty = role === 'FACULTY'
  const isStudent = existing?.role === 'STUDENT'
  // Admin <-> Super Admin is the only role change the API allows, and never on your own account.
  const canChangeRole = !editing || (!existing?.is_self && (existing?.role === 'PLACEMENT_ADMIN' || existing?.role === 'SUPER_ADMIN'))

  function validate(): Record<string, string> {
    const next: Record<string, string> = {}
    if (!name.trim()) next.name = 'Enter a name.'
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) next.email = 'Enter a valid email address.'
    if (!editing) {
      if (password.length < 8) next.password = PASSWORD_HINT
      else if (password !== confirm) next.password_confirmation = "The passwords don't match."
    }
    if (isFaculty) {
      if (!employeeCode.trim()) next.employee_code = 'Enter the employee code.'
      if (!departmentId) next.department_id = 'Choose a department.'
    }
    return next
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const local = validate()
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    const facultyFields = isFaculty
      ? { employee_code: employeeCode.trim(), department_id: Number(departmentId), designation: designation.trim() || null, phone: phone.trim() || null }
      : {}
    try {
      let saved: ManagedUser
      if (existing) {
        const payload: UpdateUserPayload = { name: name.trim(), email: email.trim(), ...facultyFields }
        if (canChangeRole && role !== existing.role && role !== 'FACULTY') payload.role = role
        saved = await superAdminService.users.update(existing.id, payload)
      } else {
        const payload: CreateUserPayload = { name: name.trim(), email: email.trim(), role, password, password_confirmation: confirm, ...facultyFields }
        saved = await superAdminService.users.create(payload)
      }
      toast('success', existing ? 'Account updated.' : `${saved.name}'s account is ready.`)
      onSaved(saved)
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save this account.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  const roleOptions = editing && canChangeRole ? STAFF_ROLES.filter((r) => r.value !== 'FACULTY') : STAFF_ROLES

  return (
    <Modal
      open
      onClose={onClose}
      title={editing ? `Edit ${existing?.name}` : 'Add staff account'}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="user-form" isLoading={busy}>{editing ? 'Save changes' : 'Create account'}</Button>
        </>
      }
    >
      <form id="user-form" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="u-name">Full name</label>
            <Input id="u-name" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} error={errors.name} autoFocus autoComplete="off" />
          </div>
          <div>
            <label className="label" htmlFor="u-email">Email</label>
            <Input id="u-email" type="email" maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email} autoComplete="off" />
          </div>
        </div>

        <div>
          <label className="label" htmlFor="u-role">Role</label>
          {canChangeRole ? (
            <Select id="u-role" value={role} onChange={(e) => setRole(e.target.value as StaffRole)} error={errors.role}>
              {roleOptions.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </Select>
          ) : (
            <p id="u-role" className="input flex items-center text-ink-soft">{existing?.role_label}</p>
          )}
          <p className="mt-1 text-xs text-ink-faint">
            {canChangeRole
              ? STAFF_ROLES.find((r) => r.value === role)?.hint
              : existing?.is_self
                ? "You can't change your own role."
                : isStudent
                  ? 'Students keep their role - their applications and internships depend on it.'
                  : 'Faculty keep their role - their coordinator assignments depend on it.'}
          </p>
        </div>

        {isFaculty && (
          <fieldset className="space-y-4 rounded-xl border border-hairline p-4">
            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-ink-faint">Faculty details</legend>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="label" htmlFor="u-code">Employee code</label>
                <Input id="u-code" maxLength={50} value={employeeCode} onChange={(e) => setEmployeeCode(e.target.value.toUpperCase())} error={errors.employee_code} placeholder="e.g. FAC-CSE-014" />
              </div>
              <div>
                <label className="label" htmlFor="u-dept">Department</label>
                <Select id="u-dept" value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} error={errors.department_id}>
                  <option value="">{departments.isLoading ? 'Loading…' : 'Choose…'}</option>
                  {(departments.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </Select>
              </div>
              <div>
                <label className="label" htmlFor="u-desig">Designation <span className="font-normal text-ink-faint">(optional)</span></label>
                <Input id="u-desig" maxLength={100} value={designation} onChange={(e) => setDesignation(e.target.value)} error={errors.designation} placeholder="e.g. Assistant Professor" />
              </div>
              <div>
                <label className="label" htmlFor="u-phone">Phone <span className="font-normal text-ink-faint">(optional)</span></label>
                <Input id="u-phone" type="tel" maxLength={20} value={phone} onChange={(e) => setPhone(e.target.value)} error={errors.phone} />
              </div>
            </div>
          </fieldset>
        )}

        {!editing && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="u-pass">Temporary password</label>
              <Input id="u-pass" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} />
            </div>
            <div>
              <label className="label" htmlFor="u-pass2">Confirm password</label>
              <Input id="u-pass2" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} error={errors.password_confirmation} />
            </div>
            <p className="text-xs text-ink-faint sm:col-span-2">{PASSWORD_HINT} Share it with them securely; you can reset it later from their account.</p>
          </div>
        )}
      </form>
    </Modal>
  )
}
