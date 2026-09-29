import { useState } from 'react'
import { CheckCircle2, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Drawer } from '@/components/ui/Drawer'
import { EmptyState } from '@/components/ui/EmptyState'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Pagination } from '@/components/ui/Pagination'
import { toast } from '@/components/ui/Toast'
import { DetailItem, MetaBadge } from '@/components/common/Cells'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { parseApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/dateFormat'
import { EXCEL_BATCH_STATUS, EXCEL_OUTCOME } from '@/utils/statusMeta'
import type { ExcelConfirmResult, ExcelPreviewRow, ExcelRollbackResult } from '@/types/admin'

/** The few raw cells that identify a row, whatever the import type (the columns differ per type). */
function rowSummary(raw: Record<string, string | null>): string {
  return Object.values(raw).filter((v): v is string => Boolean(v && v.trim())).slice(0, 4).join(' · ')
}

interface ExcelBatchDrawerProps {
  batchId: number | null
  onClose: () => void
  onChanged: () => void
}

/**
 * One import batch: its counts, the row-by-row preview the backend
 * built (outcome + what would change), and the two actions the batch
 * status allows - confirm (READY_FOR_CONFIRMATION) and roll back
 * (CONFIRMED). Rows with ERROR are never applied; selecting a subset of
 * the applicable rows sends row_ids so only those are applied.
 */
export function ExcelBatchDrawer({ batchId, onClose, onChanged }: ExcelBatchDrawerProps) {
  const [page, setPage] = useState(1)
  const { data, isLoading, error, reload } = useApiResource(() => adminService.excel.preview(batchId as number, { page, per_page: 25 }), batchId === null ? null : `${batchId}:${page}`)
  const [selected, setSelected] = useState<Set<number> | null>(null)
  const [dialog, setDialog] = useState<'confirm' | 'rollback' | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [confirmResult, setConfirmResult] = useState<ExcelConfirmResult['result'] | null>(null)
  const [rollbackResult, setRollbackResult] = useState<ExcelRollbackResult['result'] | null>(null)

  const batch = data?.batch
  const items = data?.items ?? []
  const applicable = items.filter((r) => r.outcome !== 'ERROR' && !r.applied)
  const allApplicableIds = applicable.map((r) => r.id)
  const effective = selected ?? new Set(allApplicableIds)
  const partial = selected !== null && allApplicableIds.some((id) => !selected.has(id))

  function toggle(id: number) {
    const next = new Set(effective)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setSelected(next)
  }

  async function act() {
    if (!batchId) return
    setBusy(true)
    setActionError(null)
    try {
      if (dialog === 'confirm') {
        const result = await adminService.excel.confirm(batchId, partial ? [...effective] : undefined)
        setConfirmResult(result.result)
        toast('success', `Import confirmed - ${result.result.applied} applied.`)
      } else {
        const result = await adminService.excel.rollback(batchId)
        setRollbackResult(result.result)
        toast(result.result.conflicts > 0 ? 'warning' : 'success', result.result.conflicts > 0 ? `Rolled back with ${result.result.conflicts} conflict(s).` : 'Import rolled back.')
      }
      setDialog(null)
      setSelected(null)
      reload()
      onChanged()
    } catch (err) {
      setDialog(null)
      setActionError(parseApiError(err, 'Unable to complete that action.').message)
    } finally {
      setBusy(false)
    }
  }

  const c = batch?.counts
  return (
    <>
      <Drawer open={batchId !== null} onClose={onClose} title={batch?.original_filename ?? 'Import batch'} width="lg">
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-10 w-2/3" />
            <LoadingSkeleton className="h-16 w-full" />
            <LoadingSkeleton className="h-40 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : batch ? (
          <div className="space-y-6">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-sm font-medium text-ink">{batch.import_type_label}</p>
                <p className="text-xs text-ink-faint">Uploaded {formatDateTime(batch.created_at)}</p>
              </div>
              <MetaBadge map={EXCEL_BATCH_STATUS} value={batch.status} />
            </div>

            {actionError && <div role="alert" className="rounded-lg border border-error/20 bg-error-bg px-3.5 py-2.5 text-sm text-error">{actionError}</div>}
            {batch.status === 'FAILED' && batch.error_message && (
              <div role="alert" className="rounded-xl border border-error/20 bg-error-bg p-4">
                <h4 className="mb-1 text-sm font-semibold text-error">This file couldn't be imported</h4>
                <p className="text-sm text-ink">{batch.error_message}</p>
              </div>
            )}

            {c && (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                {[['Rows', c.total_rows], ['Matched', c.matched_rows], ['Valid', c.valid_rows], ['Warnings', c.warning_rows], ['Errors', c.error_rows]].map(([label, value]) => (
                  <div key={label as string} className="rounded-xl border border-hairline p-3 text-center">
                    <p className="font-display text-xl font-semibold tabular-nums text-ink">{value}</p>
                    <p className="text-xs text-ink-faint">{label}</p>
                  </div>
                ))}
              </div>
            )}

            <div className="flex flex-wrap gap-2">
              {batch.status === 'READY_FOR_CONFIRMATION' && (
                <Button onClick={() => setDialog('confirm')} disabled={effective.size === 0}>
                  <CheckCircle2 className="h-4 w-4" />
                  {partial ? `Confirm ${effective.size} selected row${effective.size === 1 ? '' : 's'}` : 'Confirm import'}
                </Button>
              )}
              {batch.status === 'CONFIRMED' && (
                <Button variant="secondary" onClick={() => setDialog('rollback')}>
                  <RotateCcw className="h-4 w-4" />
                  Roll back
                </Button>
              )}
              {batch.status === 'READY_FOR_CONFIRMATION' && c && c.error_rows > 0 && <p className="self-center text-xs text-ink-faint">{c.error_rows} row{c.error_rows === 1 ? '' : 's'} with errors will be skipped.</p>}
            </div>

            {confirmResult && (
              <p className="rounded-lg bg-success-bg px-3.5 py-2.5 text-sm text-success">
                Applied {confirmResult.applied} · failed {confirmResult.failed} · skipped {confirmResult.skipped_errors}
              </p>
            )}
            {rollbackResult && (
              <div className="rounded-lg bg-sidebar p-3.5 text-sm text-ink">
                <p>Reverted {rollbackResult.reverted} · conflicts {rollbackResult.conflicts} · not rollbackable {rollbackResult.not_rollbackable}</p>
                {[...new Set(rollbackResult.details.filter((d) => d.result !== 'REVERTED' && d.message).map((d) => d.message as string))].map((message) => (
                  <p key={message} className="mt-1 text-xs text-ink-soft">{message}</p>
                ))}
                {rollbackResult.conflicts > 0 && <p className="mt-1 text-xs text-ink-soft">Those records were left as they are now. The import stays confirmed.</p>}
              </div>
            )}

            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">Rows</h4>
              {items.length === 0 ? (
                <div className="card"><EmptyState title="No rows to show" description={batch.status === 'FAILED' ? 'The file was rejected before any rows were read.' : 'This batch has no rows.'} /></div>
              ) : (
                <div className="card overflow-x-auto">
                  <table className="w-full text-left text-sm">
                    <thead>
                      <tr className="border-b border-hairline text-xs font-semibold uppercase tracking-wide text-ink-soft">
                        {batch.status === 'READY_FOR_CONFIRMATION' && (
                          <th className="w-10 px-3 py-2">
                            <input
                              type="checkbox"
                              aria-label="Select all applicable rows"
                              className="h-4 w-4 accent-[var(--color-primary)]"
                              checked={applicable.length > 0 && effective.size >= applicable.length}
                              disabled={applicable.length === 0}
                              onChange={(e) => setSelected(e.target.checked ? new Set(allApplicableIds) : new Set())}
                            />
                          </th>
                        )}
                        <th className="px-3 py-2">Row</th>
                        <th className="px-3 py-2">Data</th>
                        <th className="px-3 py-2">Result</th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.map((row) => (
                        <PreviewRow key={row.id} row={row} selectable={batch.status === 'READY_FOR_CONFIRMATION'} checked={effective.has(row.id)} onToggle={() => toggle(row.id)} />
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <div className="mt-3">
                <Pagination page={page} totalPages={data?.meta.last_page ?? 1} onChange={setPage} />
              </div>
            </section>

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Uploaded by">{batch.uploaded_by?.name}</DetailItem>
              <DetailItem label="Confirmed">{batch.confirmed_at ? `${formatDateTime(batch.confirmed_at)}${batch.confirmed_by ? ` · ${batch.confirmed_by.name}` : ''}` : null}</DetailItem>
              {batch.rolled_back_at && <DetailItem label="Rolled back">{formatDateTime(batch.rolled_back_at)}</DetailItem>}
            </dl>
          </div>
        ) : null}
      </Drawer>

      <ConfirmDialog
        open={dialog === 'confirm'}
        onClose={() => setDialog(null)}
        onConfirm={act}
        isConfirming={busy}
        title="Apply this import?"
        description={`${effective.size} row${effective.size === 1 ? '' : 's'} will be applied to live records. Rows with errors are skipped. You can roll the import back afterwards if nothing has changed since.`}
        confirmLabel="Apply import"
      />
      <ConfirmDialog
        open={dialog === 'rollback'}
        onClose={() => setDialog(null)}
        onConfirm={act}
        isConfirming={busy}
        title="Roll back this import?"
        description="Records this import changed are put back as they were. Anything edited again since the import is left alone and reported as a conflict."
        confirmLabel="Roll back"
        tone="danger"
      />
    </>
  )
}

function PreviewRow({ row, selectable, checked, onToggle }: { row: ExcelPreviewRow; selectable: boolean; checked: boolean; onToggle: () => void }) {
  const canSelect = row.outcome !== 'ERROR' && !row.applied
  return (
    <tr className="border-b border-hairline align-top last:border-0">
      {selectable && (
        <td className="px-3 py-2.5">
          {canSelect && <input type="checkbox" aria-label={`Apply row ${row.row_number}`} className="h-4 w-4 accent-[var(--color-primary)]" checked={checked} onChange={onToggle} />}
        </td>
      )}
      <td className="whitespace-nowrap px-3 py-2.5 text-ink-soft">#{row.row_number}</td>
      <td className="px-3 py-2.5 text-ink">{rowSummary(row.raw_row) || '—'}</td>
      <td className="px-3 py-2.5">
        <div className="flex flex-wrap items-center gap-2">
          <MetaBadge map={EXCEL_OUTCOME} value={row.outcome} />
          {row.applied && <span className="text-xs text-success">applied</span>}
        </div>
        {row.messages.map((m) => <p key={m} className="mt-1 text-xs text-ink-soft">{m}</p>)}
        {row.changes.map((ch, i) => (
          <p key={i} className="mt-1 text-xs text-ink-soft">{ch.field}: <span className="text-ink-faint">{ch.old_value ?? '—'}</span> → <span className="text-ink">{ch.new_value ?? '—'}</span></p>
        ))}
        {row.apply_error && <p className="mt-1 text-xs text-error">{row.apply_error}</p>}
      </td>
    </tr>
  )
}
