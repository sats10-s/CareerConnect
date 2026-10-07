import { useState, type FormEvent, type ReactNode } from 'react'
import { Landmark, Layers, Pencil, Plus, Trash2 } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { IconButton } from '@/components/ui/IconButton'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { toast } from '@/components/ui/Toast'
import { ListState } from '@/components/common/ListState'
import { useApiResource } from '@/hooks/useApiResource'
import { superAdminService } from '@/services/superAdminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import type { BatchRecord, DepartmentRecord } from '@/types/superAdmin'

/* ---------- shared bits ---------- */

function FormModal({ id, title, submitLabel, busy, error, onClose, onSubmit, children }: {
  id: string
  title: string
  submitLabel: string
  busy: boolean
  error: string | null
  onClose: () => void
  onSubmit: (e: FormEvent) => void
  children: ReactNode
}) {
  return (
    <Modal
      open
      onClose={onClose}
      title={title}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form={id} isLoading={busy}>{submitLabel}</Button>
        </>
      }
    >
      <form id={id} onSubmit={onSubmit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        {children}
      </form>
    </Modal>
  )
}

function RowActions({ name, inUse, onEdit, onDelete }: { name: string; inUse: boolean; onEdit: () => void; onDelete: () => void }) {
  return (
    <div className="flex justify-end gap-1">
      <IconButton icon={Pencil} label={`Edit ${name}`} onClick={onEdit} />
      <IconButton
        icon={Trash2}
        label={inUse ? `${name} is in use and can't be deleted` : `Delete ${name}`}
        title={inUse ? 'In use - move or remove its people first' : undefined}
        onClick={onDelete}
        disabled={inUse}
        className="hover:text-error disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-ink-soft"
      />
    </div>
  )
}

function useDelete<T extends { id: number; name: string }>(remove: (id: number) => Promise<unknown>, onDone: () => void) {
  const [target, setTarget] = useState<T | null>(null)
  const [busy, setBusy] = useState(false)
  async function confirm() {
    if (!target) return
    setBusy(true)
    try {
      await remove(target.id)
      toast('success', `${target.name} deleted.`)
      setTarget(null)
      onDone()
    } catch (err) {
      toast('error', parseApiError(err, `Unable to delete ${target.name}.`).message)
    } finally {
      setBusy(false)
    }
  }
  return { target, setTarget, busy, confirm }
}

const count = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`

/* ---------- Departments ---------- */

function DepartmentFormModal({ existing, onClose, onSaved }: { existing?: DepartmentRecord; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(existing?.name ?? '')
  const [code, setCode] = useState(existing?.code ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const local: Record<string, string> = {}
    if (!name.trim()) local.name = 'Enter the department name.'
    if (!code.trim()) local.code = 'Enter a short code.'
    else if (!/^[A-Z0-9&-]+$/.test(code.trim())) local.code = 'Use capital letters, numbers, & or - only.'
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    try {
      const payload = { name: name.trim(), code: code.trim() }
      if (existing) await superAdminService.departments.update(existing.id, payload)
      else await superAdminService.departments.create(payload)
      toast('success', existing ? 'Department updated.' : 'Department added.')
      onSaved()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save this department.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <FormModal id="dept-form" title={existing ? `Edit ${existing.name}` : 'Add department'} submitLabel={existing ? 'Save changes' : 'Add department'} busy={busy} error={error} onClose={onClose} onSubmit={submit}>
      <div>
        <label className="label" htmlFor="d-name">Department name</label>
        <Input id="d-name" maxLength={120} value={name} onChange={(e) => setName(e.target.value)} error={errors.name} placeholder="e.g. Computer Science & Engineering" autoFocus />
      </div>
      <div>
        <label className="label" htmlFor="d-code">Code</label>
        <Input id="d-code" maxLength={16} value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} error={errors.code} placeholder="e.g. CSE" />
        <p className="mt-1 text-xs text-ink-faint">Shown on the registration form and in reports.</p>
      </div>
    </FormModal>
  )
}

/** Super Admin > Departments: GET/POST/PATCH/DELETE /super-admin/departments. Deleting is refused while students or faculty belong to it. */
export function SuperAdminDepartmentsPage() {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.departments.list(), 'super-admin-departments')
  const [editing, setEditing] = useState<DepartmentRecord | 'new' | null>(null)
  const del = useDelete<DepartmentRecord>(superAdminService.departments.remove, reload)
  const rows = data ?? []
  const inUse = (r: DepartmentRecord) => r.students_count + r.faculty_count > 0

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader
        title="Departments"
        description="The departments students and faculty belong to."
        action={<Button onClick={() => setEditing('new')}><Plus className="h-4 w-4" />Add department</Button>}
      />
      <ListState
        isLoading={isLoading}
        error={error}
        isEmpty={rows.length === 0}
        hasLoadedOnce={data !== null}
        onRetry={reload}
        empty={<EmptyState icon={Landmark} title="No departments yet" description="Add departments so students can register and faculty can be assigned." action={<Button onClick={() => setEditing('new')}>Add department</Button>} />}
      >
        <DataTable
          columns={[
            { key: 'name', header: 'Department', render: (r: DepartmentRecord) => <span className="text-sm font-medium text-ink">{r.name}</span> },
            { key: 'code', header: 'Code', render: (r: DepartmentRecord) => <span className="rounded bg-surface-raised px-1.5 py-0.5 font-mono text-xs text-ink-soft">{r.code}</span> },
            { key: 'students', header: 'Students', render: (r: DepartmentRecord) => <span className="tabular-nums">{r.students_count}</span> },
            { key: 'faculty', header: 'Faculty', render: (r: DepartmentRecord) => <span className="tabular-nums">{r.faculty_count}</span> },
            { key: 'actions', header: '', className: 'w-24', render: (r: DepartmentRecord) => <RowActions name={r.name} inUse={inUse(r)} onEdit={() => setEditing(r)} onDelete={() => del.setTarget(r)} /> },
          ]}
          rows={rows}
          rowKey={(r) => r.id}
          renderMobileCard={(r) => (
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-sm font-medium text-ink">{r.name} <span className="text-xs font-normal text-ink-faint">· {r.code}</span></p>
                <p className="mt-0.5 text-xs text-ink-faint">{count(r.students_count, 'student')} · {r.faculty_count} faculty</p>
              </div>
              <RowActions name={r.name} inUse={inUse(r)} onEdit={() => setEditing(r)} onDelete={() => del.setTarget(r)} />
            </div>
          )}
        />
      </ListState>
      <p className="text-xs text-ink-faint">A department can only be deleted once no students or faculty belong to it.</p>

      {editing && <DepartmentFormModal existing={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} onSaved={reload} />}
      <ConfirmDialog
        open={del.target !== null}
        onClose={() => del.setTarget(null)}
        onConfirm={del.confirm}
        isConfirming={del.busy}
        tone="danger"
        title={`Delete ${del.target?.name ?? 'department'}?`}
        confirmLabel="Delete"
        description="It will disappear from registration and filters. This can't be undone."
      />
    </div>
  )
}

/* ---------- Batches ---------- */

function BatchFormModal({ existing, onClose, onSaved }: { existing?: BatchRecord; onClose: () => void; onSaved: () => void }) {
  const thisYear = new Date().getFullYear()
  const [start, setStart] = useState(String(existing?.start_year ?? thisYear))
  const [end, setEnd] = useState(String(existing?.end_year ?? thisYear + 4))
  const [name, setName] = useState(existing?.name ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const defaultName = `${start || '____'}-${end || '____'}`

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const s = Number(start)
    const e = Number(end)
    const local: Record<string, string> = {}
    if (!/^\d{4}$/.test(start)) local.start_year = 'Enter a 4-digit year.'
    if (!/^\d{4}$/.test(end)) local.end_year = 'Enter a 4-digit year.'
    else if (!local.start_year && e <= s) local.end_year = 'Must be after the start year.'
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true)
    try {
      const payload = { start_year: s, end_year: e, name: name.trim() || undefined }
      if (existing) await superAdminService.batches.update(existing.id, payload)
      else await superAdminService.batches.create(payload)
      toast('success', existing ? 'Batch updated.' : 'Batch added.')
      onSaved()
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save this batch.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <FormModal id="batch-form" title={existing ? `Edit ${existing.name}` : 'Add batch'} submitLabel={existing ? 'Save changes' : 'Add batch'} busy={busy} error={error} onClose={onClose} onSubmit={submit}>
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="label" htmlFor="b-start">Start year</label>
          <Input id="b-start" inputMode="numeric" maxLength={4} value={start} onChange={(e) => setStart(e.target.value.replace(/\D/g, ''))} error={errors.start_year} autoFocus />
        </div>
        <div>
          <label className="label" htmlFor="b-end">Graduation year</label>
          <Input id="b-end" inputMode="numeric" maxLength={4} value={end} onChange={(e) => setEnd(e.target.value.replace(/\D/g, ''))} error={errors.end_year} />
        </div>
      </div>
      <div>
        <label className="label" htmlFor="b-name">Display name <span className="font-normal text-ink-faint">(optional)</span></label>
        <Input id="b-name" maxLength={32} value={name} onChange={(e) => setName(e.target.value)} error={errors.name} placeholder={defaultName} />
        <p className="mt-1 text-xs text-ink-faint">Leave blank to use {defaultName}.</p>
      </div>
    </FormModal>
  )
}

/** Super Admin > Batches: GET/POST/PATCH/DELETE /super-admin/batches. Deleting is refused while students are registered under it. */
export function SuperAdminBatchesPage() {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.batches.list(), 'super-admin-batches')
  const [editing, setEditing] = useState<BatchRecord | 'new' | null>(null)
  const del = useDelete<BatchRecord>(superAdminService.batches.remove, reload)
  const rows = data ?? []

  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader
        title="Batches"
        description="The graduating batches students register under."
        action={<Button onClick={() => setEditing('new')}><Plus className="h-4 w-4" />Add batch</Button>}
      />
      <ListState
        isLoading={isLoading}
        error={error}
        isEmpty={rows.length === 0}
        hasLoadedOnce={data !== null}
        onRetry={reload}
        empty={<EmptyState icon={Layers} title="No batches yet" description="Add the current batches so students can register." action={<Button onClick={() => setEditing('new')}>Add batch</Button>} />}
      >
        <DataTable
          columns={[
            { key: 'name', header: 'Batch', render: (r: BatchRecord) => <span className="text-sm font-medium text-ink">{r.name}</span> },
            { key: 'years', header: 'Years', render: (r: BatchRecord) => <span className="text-sm tabular-nums text-ink-soft">{r.start_year} – {r.end_year}</span> },
            { key: 'students', header: 'Students', render: (r: BatchRecord) => <span className="tabular-nums">{r.students_count}</span> },
            { key: 'actions', header: '', className: 'w-24', render: (r: BatchRecord) => <RowActions name={r.name} inUse={r.students_count > 0} onEdit={() => setEditing(r)} onDelete={() => del.setTarget(r)} /> },
          ]}
          rows={rows}
          rowKey={(r) => r.id}
          renderMobileCard={(r) => (
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-sm font-medium text-ink">{r.name}</p>
                <p className="mt-0.5 text-xs tabular-nums text-ink-faint">{r.start_year} – {r.end_year} · {count(r.students_count, 'student')}</p>
              </div>
              <RowActions name={r.name} inUse={r.students_count > 0} onEdit={() => setEditing(r)} onDelete={() => del.setTarget(r)} />
            </div>
          )}
        />
      </ListState>
      <p className="text-xs text-ink-faint">A batch can only be deleted once no students are registered under it.</p>

      {editing && <BatchFormModal existing={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} onSaved={reload} />}
      <ConfirmDialog
        open={del.target !== null}
        onClose={() => del.setTarget(null)}
        onConfirm={del.confirm}
        isConfirming={del.busy}
        tone="danger"
        title={`Delete batch ${del.target?.name ?? ''}?`}
        confirmLabel="Delete"
        description="It will disappear from registration and filters. This can't be undone."
      />
    </div>
  )
}
