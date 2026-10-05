import { useEffect, useState } from 'react'
import { AlertTriangle, ArrowRight, CheckCircle2, Download, KeyRound, Plus, RefreshCw, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Modal } from '@/components/ui/Modal'
import { Pagination } from '@/components/ui/Pagination'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { StatusBadge, type StatusTone } from '@/components/ui/StatusBadge'
import { Tabs } from '@/components/ui/Tabs'
import { toast } from '@/components/ui/Toast'
import { useApiResource } from '@/hooks/useApiResource'
import { studentDataService } from '@/services/studentDataService'
import { parseApiError, parseBlobApiError } from '@/utils/apiError'
import { saveBlob } from '@/utils/downloadBlob'
import type { OneTimePassword, StudentImportBatch, StudentImportRow, StudentImportTab } from '@/types/studentData'
import { CreateDepartmentModal } from './CreateDepartmentModal'
import { STATUS_META } from './statusMeta'


const PER_PAGE = 25

function display(value: string | number | null | undefined): string {
  return value === null || value === undefined || value === '' ? '—' : String(value)
}

/**
 * The review step of a Student Data import: per-sheet counts, the
 * New / Updated / Unchanged / Warnings / Errors tabs, and Cancel / Confirm.
 * While the confirm job runs it polls the batch for progress; afterwards it
 * shows the result and the one-time passwords (once).
 */
export function ImportPreview({ batch: initial, onChanged }: { batch: StudentImportBatch; onChanged?: (batch: StudentImportBatch) => void }) {
  const [batch, setBatch] = useState(initial)
  const isResults = batch.type === 'academic_update'
  const [tab, setTab] = useState<StudentImportTab>(isResults ? 'updated' : 'new')
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState<'confirm' | 'cancel' | 'recheck' | 'issues' | null>(null)
  const [askConfirm, setAskConfirm] = useState(false)
  const [createFor, setCreateFor] = useState<string | null>(null)
  const [passwords, setPasswords] = useState<OneTimePassword[] | null>(null)
  const [waitingTooLong, setWaitingTooLong] = useState(false)

  useEffect(() => setBatch(initial), [initial])

  const update = (next: StudentImportBatch) => {
    setBatch(next)
    onChanged?.(next)
  }

  // Poll while the queued confirm job runs.
  useEffect(() => {
    if (batch.status !== 'PROCESSING') return
    const started = Date.now()
    const timer = window.setInterval(async () => {
      try {
        const next = await studentDataService.imports.show(batch.id)
        setWaitingTooLong(next.status === 'PROCESSING' && next.progress.done === 0 && Date.now() - started > 15000)
        if (next.status !== 'PROCESSING' || next.progress.done !== batch.progress.done) update(next)
      } catch {
        /* keep polling */
      }
    }, 1500)
    return () => window.clearInterval(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [batch.id, batch.status, batch.progress.done])

  const rows = useApiResource(
    () => studentDataService.imports.rows(batch.id, { tab, page, per_page: PER_PAGE }),
    `${batch.id}-${tab}-${page}-${batch.status}-${batch.counts.new}-${batch.counts.error}`,
  )

  const confirmable = batch.counts.new + batch.counts.updated
  const tabs = [
    ...(isResults ? [] : [{ key: 'new', label: `New (${batch.counts.new})` }]),
    { key: 'updated', label: `Updated (${batch.counts.updated})` },
    { key: 'unchanged', label: `Unchanged (${batch.counts.unchanged})` },
    { key: 'warnings', label: `Warnings (${batch.counts.warning})` },
    { key: 'errors', label: `Errors (${batch.counts.error})` },
  ]
  const status = STATUS_META[batch.status] ?? { label: batch.status, tone: 'neutral' as StatusTone }
  const percent = batch.progress.total ? Math.round((batch.progress.done / batch.progress.total) * 100) : 0
  const editable = batch.status === 'READY_FOR_CONFIRMATION' || batch.status === 'VALIDATED'
  const noun = isResults ? 'student result' : 'student'

  async function run(kind: 'confirm' | 'cancel' | 'recheck') {
    setBusy(kind)
    try {
      const next = kind === 'confirm'
        ? await studentDataService.imports.confirm(batch.id)
        : kind === 'cancel'
          ? await studentDataService.imports.cancel(batch.id)
          : await studentDataService.imports.recheck(batch.id)
      update(next)
      if (kind === 'cancel') toast('success', 'Import cancelled. Nothing was changed.')
      if (kind === 'recheck') toast('success', 'Re-checked against the current departments.')
    } catch (err) {
      toast('error', parseApiError(err, 'That did not work. Please try again.').message)
    } finally {
      setBusy(null)
      setAskConfirm(false)
    }
  }

  async function downloadIssues() {
    setBusy('issues')
    try {
      saveBlob(await studentDataService.imports.issues(batch.id), `student-import-${batch.id}-issues.xlsx`)
    } catch (err) {
      toast('error', (await parseBlobApiError(err, 'Unable to download the issues.')).message)
    } finally {
      setBusy(null)
    }
  }

  async function revealPasswords() {
    try {
      const items = await studentDataService.imports.oneTimePasswords(batch.id)
      setPasswords(items)
      update({ ...batch, has_one_time_passwords: false })
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to load the one-time passwords.').message)
    }
  }

  return (
    <div className="space-y-5">
      <div className="card space-y-4 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-ink">{batch.original_filename}</p>
            <p className="mt-0.5 text-xs text-ink-faint">
              {batch.type_label} · {batch.total_rows} rows{batch.semester_label ? ` · ${batch.semester_label}` : ''}
            </p>
          </div>
          <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
        </div>

        {batch.sheets.length > 0 && (
          <div className="grid gap-2 sm:grid-cols-2">
            {batch.sheets.map((sheet) => (
              <div key={sheet.name} className="rounded-xl border border-hairline px-3.5 py-3 text-sm">
                <p className="font-medium text-ink">{sheet.name}</p>
                {sheet.skipped ? (
                  <p className="mt-1 text-xs text-warning">{sheet.reason}</p>
                ) : (
                  <p className="mt-1 text-xs text-ink-soft">
                    {sheet.rows} rows · {isResults ? '' : `${sheet.new} new · `}{sheet.updated} updated · {sheet.unchanged} unchanged · {sheet.error} errors
                  </p>
                )}
              </div>
            ))}
          </div>
        )}

        {batch.unknown_departments.length > 0 && editable && (
          <div className="rounded-xl border border-error-line bg-error-bg px-4 py-3 text-sm">
            <p className="font-medium text-error">Departments not recognised</p>
            <ul className="mt-2 space-y-1.5">
              {batch.unknown_departments.map((d) => (
                <li key={d.value} className="flex flex-wrap items-center justify-between gap-2 text-ink-soft">
                  <span>“{d.value}” · {d.rows} row{d.rows === 1 ? '' : 's'}</span>
                  <button type="button" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" onClick={() => setCreateFor(d.value)}>
                    <Plus className="h-3.5 w-3.5" /> Create department
                  </button>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-ink-faint">Or map the spelling to an existing department under Settings, then re-check.</p>
          </div>
        )}

        {batch.status === 'PROCESSING' && (
          <div className="space-y-2">
            <div className="flex justify-between text-xs text-ink-soft">
              <span>Importing {batch.progress.done} of {batch.progress.total} {noun}s…</span>
              <span className="tabular-nums">{percent}%</span>
            </div>
            <ProgressBar value={percent} label="Import progress" />
            {waitingTooLong && (
              <p className="text-xs text-warning">
                Still waiting to start. The import runs in the background queue - make sure <code>php artisan queue:work</code> is running on the server.
              </p>
            )}
          </div>
        )}

        {batch.status === 'CONFIRMED' && batch.result && (
          <div className="flex flex-wrap items-center gap-3 rounded-xl bg-success-bg px-4 py-3 text-sm text-success">
            <CheckCircle2 className="h-4 w-4" />
            <span>
              {isResults ? `${batch.result.updated} students updated` : `${batch.result.created} students created, ${batch.result.updated} updated`}
              {batch.result.failed > 0 ? `, ${batch.result.failed} could not be applied` : ''}.
            </span>
          </div>
        )}

        {batch.has_one_time_passwords && (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-hairline bg-warning-bg px-4 py-3 text-sm">
            <span className="text-warning">
              {batch.result?.one_time_passwords ?? 'Some'} students had no valid mobile number and got a one-time password. You can view them only once.
            </span>
            <Button variant="secondary" onClick={revealPasswords}>
              <KeyRound className="h-4 w-4" /> Show one-time passwords
            </Button>
          </div>
        )}

        {(batch.status === 'FAILED' || batch.error_message) && (
          <p className="flex items-start gap-2 text-sm text-error"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />{batch.error_message}</p>
        )}

        <div className="flex flex-wrap gap-2">
          {editable && (
            <>
              <Button onClick={() => setAskConfirm(true)} disabled={confirmable === 0 || busy !== null} isLoading={busy === 'confirm'}>
                Confirm {confirmable} {noun}{confirmable === 1 ? '' : 's'}
              </Button>
              <Button variant="secondary" onClick={() => run('cancel')} isLoading={busy === 'cancel'} disabled={busy !== null}>
                <X className="h-4 w-4" /> Cancel
              </Button>
              <Button variant="secondary" onClick={() => run('recheck')} isLoading={busy === 'recheck'} disabled={busy !== null}>
                <RefreshCw className="h-4 w-4" /> Re-check
              </Button>
            </>
          )}
          {batch.counts.error + batch.counts.warning > 0 && (
            <Button variant="secondary" onClick={downloadIssues} isLoading={busy === 'issues'}>
              <Download className="h-4 w-4" /> Download issues as Excel
            </Button>
          )}
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="overflow-x-auto px-2 pt-1">
          <Tabs items={tabs} active={tab} onChange={(key) => { setTab(key as StudentImportTab); setPage(1) }} />
        </div>
        <RowList rows={rows.data?.items ?? []} isLoading={rows.isLoading} error={rows.error} isResults={isResults} tab={tab} />
        {rows.data && rows.data.meta.last_page > 1 && (
          <div className="border-t border-hairline px-4 py-3">
            <Pagination page={page} totalPages={rows.data.meta.last_page} onChange={setPage} />
          </div>
        )}
      </div>

      <ConfirmDialog
        open={askConfirm}
        onClose={() => setAskConfirm(false)}
        onConfirm={() => run('confirm')}
        isConfirming={busy === 'confirm'}
        title={`Import ${confirmable} ${noun}${confirmable === 1 ? '' : 's'}?`}
        confirmLabel="Confirm import"
        description={
          isResults
            ? `${batch.counts.updated} students get new CGPA/backlog figures and a notification. Rows with errors are skipped.`
            : `${batch.counts.new} new student accounts will be created (first password: their mobile number) and ${batch.counts.updated} existing students updated. Rows with errors are skipped.`
        }
      />

      {createFor && (
        <CreateDepartmentModal
          spelling={createFor}
          onClose={() => setCreateFor(null)}
          onCreated={() => {
            setCreateFor(null)
            run('recheck')
          }}
        />
      )}

      <Modal open={passwords !== null} onClose={() => setPasswords(null)} title="One-time passwords" footer={<Button onClick={() => setPasswords(null)}>Done</Button>}>
        <p className="mb-3 text-sm text-ink-soft">Give each student their password privately. They must change it at first sign-in. This list will not be shown again.</p>
        <div className="max-h-80 overflow-y-auto rounded-xl border border-hairline">
          <table className="w-full text-sm">
            <thead className="bg-sidebar text-left text-xs text-ink-faint"><tr><th className="px-3 py-2">JNTU No</th><th className="px-3 py-2">Name</th><th className="px-3 py-2">Password</th></tr></thead>
            <tbody className="divide-y divide-hairline">
              {(passwords ?? []).map((p) => (
                <tr key={p.roll_number}><td className="px-3 py-2 font-mono">{p.roll_number}</td><td className="px-3 py-2">{p.name}</td><td className="px-3 py-2 font-mono">{p.password}</td></tr>
              ))}
            </tbody>
          </table>
        </div>
      </Modal>
    </div>
  )
}

function RowList({ rows, isLoading, error, isResults, tab }: { rows: StudentImportRow[]; isLoading: boolean; error: string | null; isResults: boolean; tab: StudentImportTab }) {
  if (error) return <p className="px-5 py-6 text-sm text-error">{error}</p>
  if (isLoading && rows.length === 0) return <p className="px-5 py-6 text-sm text-ink-faint">Loading rows…</p>
  if (rows.length === 0) return <p className="px-5 py-8 text-center text-sm text-ink-faint">Nothing in this tab.</p>

  return (
    <ul className={`divide-y divide-hairline ${isLoading ? 'opacity-60' : ''}`}>
      {rows.map((row) => (
        <li key={row.id} className={`px-5 py-3.5 ${row.flagged ? 'bg-warning-bg' : ''}`}>
          <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p className="text-sm font-medium text-ink">
              {row.name ?? 'No name'} <span className="font-mono text-xs text-ink-soft">{row.roll_number || 'no JNTU No'}</span>
            </p>
            <p className="text-xs text-ink-faint">{row.sheet} · row {row.sheet_row}</p>
          </div>

          {row.category === 'new' && tab !== 'errors' && (
            <p className="mt-1 text-xs text-ink-soft">
              {[row.values.department, row.values.section && `Section ${row.values.section}`, row.values.batch && `Batch ${row.values.batch}`, row.values.entry_type === 'lateral' && 'Lateral', row.values.program === 'mtech' && 'M.Tech',
                `CGPA ${display(row.values.cgpa)}`, `Backlogs ${display(row.values.backlogs)}`].filter(Boolean).join(' · ')}
            </p>
          )}

          {row.changes.length > 0 && (
            <ul className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs">
              {row.changes.map((c) => (
                <li key={c.field} className="inline-flex items-center gap-1 text-ink-soft">
                  <span className="text-ink-faint">{c.label}</span>
                  <span className="line-through">{display(c.old_value)}</span>
                  <ArrowRight className="h-3 w-3" aria-label="to" />
                  <span className="font-medium text-ink">{display(c.new_value)}</span>
                </li>
              ))}
            </ul>
          )}

          {row.messages.length > 0 && (
            <ul className="mt-1.5 space-y-0.5 text-xs">
              {row.messages.map((m) => (
                <li key={m} className={row.outcome === 'ERROR' ? 'text-error' : 'text-warning'}>{m}</li>
              ))}
            </ul>
          )}
          {row.apply_error && <p className="mt-1 text-xs text-error">Not applied: {row.apply_error}</p>}
          {isResults && row.flagged && <p className="mt-1 text-xs font-medium text-warning">Large change - double-check before confirming.</p>}
        </li>
      ))}
    </ul>
  )
}
