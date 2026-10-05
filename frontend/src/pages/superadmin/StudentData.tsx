import { useEffect, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Trash2, Upload, Undo2 } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { FileUpload } from '@/components/ui/FileUpload'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { Pagination } from '@/components/ui/Pagination'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { toast } from '@/components/ui/Toast'
import { ImportPreview } from '@/components/superadmin/studentData/ImportPreview'
import { STATUS_META } from '@/components/superadmin/studentData/statusMeta'
import { useApiResource } from '@/hooks/useApiResource'
import { studentDataService } from '@/services/studentDataService'
import { superAdminService } from '@/services/superAdminService'
import { formatDateTime } from '@/utils/dateFormat'
import { formBanner, parseApiError } from '@/utils/apiError'
import type { StudentImportBatch, StudentImportType } from '@/types/studentData'

const TABS = [
  { key: 'import', label: 'Import' },
  { key: 'results', label: 'Results update' },
  { key: 'history', label: 'History' },
  { key: 'settings', label: 'Settings' },
]

type TabKey = 'import' | 'results' | 'history' | 'settings'

const MAX_BYTES = 10 * 1024 * 1024

/**
 * Super Admin > Student Data: the college's student file (Import), semester
 * CGPA/backlog updates (Results update), every past import (History) and the
 * department spellings + academic year the import relies on (Settings).
 */
export function SuperAdminStudentDataPage() {
  const [params, setParams] = useSearchParams()
  const raw = params.get('tab')
  const tab: TabKey = raw === 'results' || raw === 'history' || raw === 'settings' ? raw : 'import'
  const batchParam = Number(params.get('batch'))
  const openBatchId = Number.isInteger(batchParam) && batchParam > 0 ? batchParam : null

  function go(next: TabKey, batch?: number | null) {
    const p: Record<string, string> = {}
    if (next !== 'import') p.tab = next
    if (batch) p.batch = String(batch)
    setParams(p, { replace: true })
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title="Student Data" description="Import the college's student list and semester results. Every import is previewed before anything changes." />
      <div className="mb-5 overflow-x-auto">
        <Tabs items={TABS} active={tab} onChange={(key) => go(key as TabKey)} />
      </div>
      {tab === 'import' && <ImportPanel key="students" type="students" batchId={openBatchId} onBatch={(id) => go('import', id)} />}
      {tab === 'results' && <ImportPanel key="results" type="academic_update" batchId={openBatchId} onBatch={(id) => go('results', id)} />}
      {tab === 'history' && <HistoryPanel openBatchId={openBatchId} onOpen={(id) => go('history', id)} />}
      {tab === 'settings' && <SettingsPanel />}
    </div>
  )
}

function ImportPanel({ type, batchId, onBatch }: { type: StudentImportType; batchId: number | null; onBatch: (id: number | null) => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [semester, setSemester] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const [progress, setProgress] = useState(0)
  const current = useApiResource(() => studentDataService.imports.show(batchId as number), batchId)
  const isResults = type === 'academic_update'

  function pick(next: File | null) {
    setError(null)
    if (next && !/\.(xlsx|xls)$/i.test(next.name)) return setError('Choose an Excel file (.xlsx or .xls).')
    if (next && next.size > MAX_BYTES) return setError('That file is larger than 10 MB.')
    setFile(next)
  }

  async function upload() {
    if (!file) return setError('Choose the Excel file to upload.')
    setUploading(true)
    setProgress(0)
    setError(null)
    try {
      const batch = await studentDataService.imports.upload(type, file, isResults ? semester.trim() || null : null, setProgress)
      setFile(null)
      onBatch(batch.id)
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to read this file.')
      setError(parsed.fieldErrors.file ?? parsed.fieldErrors.type ?? parsed.message)
    } finally {
      setUploading(false)
    }
  }

  if (batchId) {
    if (current.isLoading && !current.data) return <p className="text-sm text-ink-faint">Loading import…</p>
    if (current.error || !current.data) return <p className="text-sm text-error">{current.error ?? 'Import not found.'}</p>
    return (
      <div className="space-y-4">
        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => onBatch(null)}>
          ← Upload another file
        </button>
        <ImportPreview batch={current.data} />
      </div>
    )
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <div className="card space-y-5 p-5">
        <div>
          <p className="label">{isResults ? 'Results file' : "College's student file"}</p>
          <FileUpload accept=".xlsx,.xls" selectedFile={file} onFileSelected={pick} disabled={uploading} helperText="Excel (.xlsx or .xls), up to 10 MB. Every sheet is read." />
          {error && <p role="alert" className="mt-2 text-sm text-error">{error}</p>}
        </div>

        {isResults && (
          <div>
            <label className="label" htmlFor="semester">Semester (optional)</label>
            <Input id="semester" value={semester} onChange={(e) => setSemester(e.target.value)} placeholder="e.g. 3-2" maxLength={40} />
            <p className="mt-1 text-xs text-ink-faint">Used when the file has no Semester column.</p>
          </div>
        )}

        {uploading && (
          <div className="space-y-1.5">
            <p className="text-xs text-ink-soft">{progress < 100 ? `Uploading… ${progress}%` : 'Checking every row…'}</p>
            <ProgressBar value={progress < 100 ? progress : 100} label="Upload progress" />
          </div>
        )}

        <Button onClick={upload} isLoading={uploading}>
          <Upload className="h-4 w-4" /> Upload and preview
        </Button>
      </div>

      <aside className="card space-y-3 p-5 text-sm text-ink-soft">
        <h3 className="text-sm font-semibold text-ink">{isResults ? 'Results update' : 'Full student list'}</h3>
        {isResults ? (
          <ul className="list-disc space-y-2 pl-5">
            <li>Columns: <strong className="text-ink">JNTU No, CGPA, No. of Backlogs</strong>, optional Semester. Other columns are ignored.</li>
            <li>Only students whose numbers changed are shown, old → new. Big jumps are highlighted.</li>
            <li>Blank cells keep the current value. Each change is kept in the student's history and they're notified.</li>
          </ul>
        ) : (
          <ul className="list-disc space-y-2 pl-5">
            <li>Columns: Name, JNTU No, Year of Joining, Gender, Department, Section, Mobile No, CGPA, No. of Backlogs, 10th Percentage, Inter Percentage/Diploma.</li>
            <li>Nothing changes until you confirm. Rows with errors are skipped.</li>
            <li>New students sign in with their JNTU No and registered mobile number, then must set their own password.</li>
            <li>Re-importing never erases: blank cells, passwords and what students entered themselves are left alone.</li>
          </ul>
        )}
      </aside>
    </div>
  )
}

function HistoryPanel({ openBatchId, onOpen }: { openBatchId: number | null; onOpen: (id: number | null) => void }) {
  const [page, setPage] = useState(1)
  const [rollbackFor, setRollbackFor] = useState<StudentImportBatch | null>(null)
  const [busy, setBusy] = useState(false)
  const list = useApiResource(() => studentDataService.imports.list({ page, per_page: 15 }), page)
  const open = useApiResource(() => studentDataService.imports.show(openBatchId as number), openBatchId)

  async function rollback() {
    if (!rollbackFor) return
    setBusy(true)
    try {
      const { result } = await studentDataService.imports.rollback(rollbackFor.id)
      toast('success', `Rolled back: ${result.removed} students removed, ${result.reverted} changes reverted${result.conflicts ? `, ${result.conflicts} left as they were (changed since)` : ''}.`)
      setRollbackFor(null)
      list.reload()
      if (openBatchId) open.reload()
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to roll back this import.').message)
    } finally {
      setBusy(false)
    }
  }

  if (openBatchId) {
    return (
      <div className="space-y-4">
        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => onOpen(null)}>← All imports</button>
        {open.data ? <ImportPreview batch={open.data} onChanged={() => list.reload()} /> : <p className="text-sm text-ink-faint">{open.error ?? 'Loading import…'}</p>}
      </div>
    )
  }

  const items = list.data?.items ?? []

  return (
    <div className="space-y-4">
      {list.error && <p className="text-sm text-error">{list.error}</p>}
      {!list.isLoading && items.length === 0 && !list.error && <div className="card p-8 text-center text-sm text-ink-faint">No imports yet.</div>}
      <ul className="space-y-3">
        {items.map((batch) => {
          const status = STATUS_META[batch.status] ?? { label: batch.status, tone: 'neutral' as const }
          return (
            <li key={batch.id} className="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
              <button type="button" className="min-w-0 text-left" onClick={() => onOpen(batch.id)}>
                <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                  {batch.type_label}
                  <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
                </p>
                <p className="mt-1 text-xs text-ink-faint">
                  {formatDateTime(batch.created_at)} · {batch.uploaded_by?.name ?? 'Unknown'} · {batch.original_filename}
                </p>
                <p className="mt-1 text-xs text-ink-soft">
                  {batch.type === 'students' ? `${batch.counts.new} new · ` : ''}{batch.counts.updated} updated · {batch.counts.unchanged} unchanged · {batch.counts.error} errors
                </p>
              </button>
              {batch.status === 'CONFIRMED' && (
                <div className="shrink-0 text-right">
                  <Button variant="secondary" disabled={!batch.can_rollback} onClick={() => setRollbackFor(batch)}>
                    <Undo2 className="h-4 w-4" /> Rollback
                  </Button>
                  {batch.rollback_blocker && <p className="mt-1 max-w-xs text-xs text-ink-faint">{batch.rollback_blocker}</p>}
                </div>
              )}
            </li>
          )
        })}
      </ul>
      {list.data && list.data.meta.last_page > 1 && <Pagination page={page} totalPages={list.data.meta.last_page} onChange={setPage} />}

      <ConfirmDialog
        open={rollbackFor !== null}
        onClose={() => setRollbackFor(null)}
        onConfirm={rollback}
        isConfirming={busy}
        tone="danger"
        title="Roll back this import?"
        confirmLabel="Roll back"
        description={
          rollbackFor?.type === 'students'
            ? 'Students this import created are deleted, and fields it changed go back to their old values (unless they have changed again since).'
            : 'CGPA and backlogs this import changed go back to their previous values. Each reversal is kept in the students’ history.'
        }
      />
    </div>
  )
}

function SettingsPanel() {
  const aliases = useApiResource(() => studentDataService.aliases.list(), 'aliases')
  const departments = useApiResource(() => superAdminService.departments.list(), 'departments')
  const settings = useApiResource(() => superAdminService.settings.get(), 'settings')
  const [alias, setAlias] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [academicYear, setAcademicYear] = useState('')
  const [aliasError, setAliasError] = useState<string | null>(null)
  const [yearError, setYearError] = useState<string | null>(null)
  const [busy, setBusy] = useState<'alias' | 'year' | null>(null)

  useEffect(() => {
    if (settings.data) setAcademicYear(settings.data.academic_year ?? '')
  }, [settings.data])

  async function addAlias(event: FormEvent) {
    event.preventDefault()
    if (!alias.trim() || !departmentId) return setAliasError('Enter the spelling and pick a department.')
    setBusy('alias')
    setAliasError(null)
    try {
      await studentDataService.aliases.create(alias.trim(), Number(departmentId))
      setAlias('')
      aliases.reload()
    } catch (err) {
      setAliasError(formBanner(parseApiError(err, 'Unable to save the alias.')))
    } finally {
      setBusy(null)
    }
  }

  async function removeAlias(id: number) {
    try {
      await studentDataService.aliases.remove(id)
      aliases.reload()
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to remove the alias.').message)
    }
  }

  async function saveYear(event: FormEvent) {
    event.preventDefault()
    if (academicYear.trim() && !/^\d{4}-\d{2,4}$/.test(academicYear.trim())) return setYearError('Use the format 2026-27.')
    setBusy('year')
    setYearError(null)
    try {
      await superAdminService.settings.update({ academic_year: academicYear.trim() || null })
      toast('success', 'Academic year saved. Students’ current year has been recalculated.')
    } catch (err) {
      setYearError(parseApiError(err, 'Unable to save the academic year.').fieldErrors.academic_year ?? 'Unable to save the academic year.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
      <section className="card p-5">
        <h2 className="text-sm font-semibold text-ink">Department spellings</h2>
        <p className="mt-1 text-sm text-ink-faint">How departments are written in the college file. Spaces, dots and case are ignored, and a department's own code or name always matches.</p>

        <form onSubmit={addAlias} className="mt-4 grid gap-2 sm:grid-cols-[1fr_1.4fr_auto]">
          <Input aria-label="Spelling in the file" placeholder="e.g. AI&ML" value={alias} onChange={(e) => setAlias(e.target.value)} maxLength={60} />
          <Select aria-label="Department" value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
            <option value="">Department…</option>
            {(departments.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </Select>
          <Button type="submit" isLoading={busy === 'alias'}>Add</Button>
        </form>
        {aliasError && <p role="alert" className="mt-2 text-sm text-error">{aliasError}</p>}

        <ul className="mt-4 divide-y divide-hairline rounded-xl border border-hairline">
          {(aliases.data ?? []).map((a) => (
            <li key={a.id} className="flex items-center justify-between gap-3 px-3.5 py-2.5 text-sm">
              <span className="font-mono text-ink">{a.alias}</span>
              <span className="min-w-0 flex-1 truncate text-ink-soft">→ {a.department.name}</span>
              <button type="button" className="rounded-md p-1 text-ink-faint hover:text-error" aria-label={`Remove ${a.alias}`} onClick={() => removeAlias(a.id)}>
                <Trash2 className="h-4 w-4" />
              </button>
            </li>
          ))}
          {aliases.data?.length === 0 && <li className="px-3.5 py-4 text-sm text-ink-faint">No spellings yet.</li>}
        </ul>
      </section>

      <section className="card h-fit p-5">
        <h2 className="text-sm font-semibold text-ink">Academic year</h2>
        <p className="mt-1 text-sm text-ink-faint">Decides each student's current year: in 2026-27 the 2023 batch is in 4th year.</p>
        <form onSubmit={saveYear} className="mt-4 flex gap-2">
          <Input aria-label="Academic year" placeholder="2026-27" value={academicYear} onChange={(e) => setAcademicYear(e.target.value)} error={yearError ?? undefined} />
          <Button type="submit" isLoading={busy === 'year'}>Save</Button>
        </form>
      </section>
    </div>
  )
}
