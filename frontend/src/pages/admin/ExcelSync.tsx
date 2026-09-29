import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { ExcelExportTab } from '@/components/admin/ExcelExportTab'
import { ExcelHistoryTab } from '@/components/admin/ExcelHistoryTab'
import { ExcelImportTab } from '@/components/admin/ExcelImportTab'

const TABS = [
  { key: 'import', label: 'Import' },
  { key: 'history', label: 'History' },
  { key: 'export', label: 'Export' },
]

type TabKey = 'import' | 'history' | 'export'

/** Placement Admin > Excel Sync: upload, review/confirm/roll back, and export (/admin/excel/*). */
export function AdminExcelSyncPage() {
  const [params, setParams] = useSearchParams()
  const raw = params.get('tab')
  const tab: TabKey = raw === 'history' || raw === 'export' ? raw : 'import'
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
      <PageHeader title="Excel Sync" description="Bring records in from Excel with a review step before anything changes, and export live data back out." />
      <div className="mb-5">
        <Tabs items={TABS} active={tab} onChange={(key) => go(key as TabKey)} />
      </div>
      {tab === 'import' && <ExcelImportTab onUploaded={(id) => go('history', id)} />}
      {tab === 'history' && <ExcelHistoryTab openBatchId={openBatchId} onOpenChange={(id) => go('history', id)} />}
      {tab === 'export' && <ExcelExportTab />}
    </div>
  )
}
