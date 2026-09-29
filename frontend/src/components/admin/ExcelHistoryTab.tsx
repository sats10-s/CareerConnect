import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyBriefcaseIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { ListState } from '@/components/common/ListState'
import { MetaBadge } from '@/components/common/Cells'
import { ExcelBatchDrawer } from './ExcelBatchDrawer'
import { useApiList } from '@/hooks/useApiList'
import { adminService } from '@/services/adminService'
import { formatDateTime } from '@/utils/dateFormat'
import { EXCEL_BATCH_STATUS } from '@/utils/statusMeta'
import type { ExcelBatch } from '@/types/admin'

export function ExcelHistoryTab({ openBatchId, onOpenChange }: { openBatchId: number | null; onOpenChange: (id: number | null) => void }) {
  const list = useApiList(adminService.excel.imports, {})

  const columns = [
    { key: 'file', header: 'File', render: (row: ExcelBatch) => <div><p className="truncate text-sm font-medium text-ink">{row.original_filename}</p><p className="text-xs text-ink-faint">{row.import_type_label}</p></div> },
    { key: 'status', header: 'Status', render: (row: ExcelBatch) => <MetaBadge map={EXCEL_BATCH_STATUS} value={row.status} /> },
    { key: 'rows', header: 'Rows', render: (row: ExcelBatch) => `${row.counts.total_rows} (${row.counts.valid_rows} valid · ${row.counts.error_rows} errors)` },
    { key: 'by', header: 'Uploaded by', render: (row: ExcelBatch) => row.uploaded_by?.name ?? '—' },
    { key: 'at', header: 'Uploaded', render: (row: ExcelBatch) => <span className="text-sm text-ink-soft">{formatDateTime(row.created_at)}</span> },
  ]

  return (
    <div>
      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={list.items.length === 0}
        hasLoadedOnce={list.meta !== null}
        onRetry={list.reload}
        empty={<EmptyState illustration={<EmptyBriefcaseIllustration />} title="No imports yet" description="Upload an Excel file from the Import tab. Each upload shows up here so you can review, confirm or roll it back." />}
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={(row) => onOpenChange(row.id)}
          renderMobileCard={(row) => (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <p className="min-w-0 truncate text-sm font-medium text-ink">{row.original_filename}</p>
                <MetaBadge map={EXCEL_BATCH_STATUS} value={row.status} />
              </div>
              <p className="text-xs text-ink-faint">{row.import_type_label} · {row.counts.total_rows} rows · {formatDateTime(row.created_at)}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>
      <ExcelBatchDrawer
        key={openBatchId ?? 'none'}
        batchId={openBatchId}
        onClose={() => onOpenChange(null)}
        onChanged={list.reload}
      />
    </div>
  )
}
