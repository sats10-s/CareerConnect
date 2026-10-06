import { useState } from 'react'
import { Download } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { toast } from '@/components/ui/Toast'
import { FilterDate, FilterSelect } from '@/components/common/FilterBar'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { metaService } from '@/services/metaService'
import type { ExportFilters } from '@/services/adminService'
import { parseBlobApiError } from '@/utils/apiError'
import { saveBlob } from '@/utils/downloadBlob'
import { EXCEL_EXPORT_TYPES } from '@/utils/statusMeta'
import type { ExcelExportType } from '@/types/admin'

const GROUPS = ['Records', 'Internships', 'Reports']
const YEARS = [1, 2, 3, 4].map((y) => ({ value: y, label: `Year ${y}` }))
const SECTIONS = ['A', 'B', 'C', 'D'].map((x) => ({ value: x, label: `Section ${x}` }))

/**
 * Downloads a live .xlsx from GET /admin/excel/exports/{type}. Nothing is
 * stored server-side - each export reflects the data at request time.
 */
export function ExcelExportTab() {
  const [type, setType] = useState<string>('APPLICATIONS')
  const [departmentId, setDepartmentId] = useState('')
  const [batchId, setBatchId] = useState('')
  const [year, setYear] = useState('')
  const [section, setSection] = useState('')
  const [applicableType, setApplicableType] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const departments = useApiResource(() => metaService.departments(), 'departments')
  const batches = useApiResource(() => metaService.batches(), 'batches')

  async function download() {
    if (dateFrom && dateTo && dateTo < dateFrom) {
      setError('The end date must be on or after the start date.')
      return
    }
    setError(null)
    setBusy(true)
    const filters: ExportFilters = {}
    if (departmentId) filters.department_id = Number(departmentId)
    if (batchId) filters.batch_id = Number(batchId)
    if (year) filters.year = Number(year)
    if (section) filters.section = section
    if (applicableType) filters.applicable_type = applicableType as 'job' | 'internship'
    if (dateFrom) filters.date_from = dateFrom
    if (dateTo) filters.date_to = dateTo
    try {
      saveBlob(await adminService.excel.export(type as ExcelExportType, filters), `${type.toLowerCase()}_export.xlsx`)
      toast('success', 'Export downloaded.')
    } catch (err) {
      const parsed = await parseBlobApiError(err, 'Unable to generate this export.')
      setError(parsed.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <div className="card space-y-5 p-5">
        <div>
          <label className="label" htmlFor="xl-export-type">What do you want to export?</label>
          <Select id="xl-export-type" value={type} onChange={(e) => setType(e.target.value)}>
            {GROUPS.map((group) => (
              <optgroup key={group} label={group}>
                {EXCEL_EXPORT_TYPES.filter((t) => t.group === group).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
              </optgroup>
            ))}
          </Select>
        </div>

        <div>
          <p className="label">Narrow it down (optional)</p>
          <div className="flex flex-wrap items-center gap-3">
            <FilterSelect label="Department" value={departmentId} onChange={setDepartmentId} options={(departments.data ?? []).map((d) => ({ value: d.id, label: d.name }))} allLabel="All departments" />
            <FilterSelect label="Batch" value={batchId} onChange={setBatchId} options={(batches.data ?? []).map((b) => ({ value: b.id, label: b.name }))} allLabel="All batches" />
            <FilterSelect label="Year" value={year} onChange={setYear} options={YEARS} allLabel="All years" />
            <FilterSelect label="Section" value={section} onChange={setSection} options={SECTIONS} allLabel="All sections" />
            <div className="w-full sm:w-44">
              <Select aria-label="Job or internship" value={applicableType} onChange={(e) => setApplicableType(e.target.value)}>
                <option value="">Jobs &amp; internships</option>
                <option value="job">Jobs only</option>
                <option value="internship">Internships only</option>
              </Select>
            </div>
            <FilterDate label="From" value={dateFrom} onChange={setDateFrom} />
            <FilterDate label="To" value={dateTo} onChange={setDateTo} />
          </div>
          <p className="mt-1.5 text-xs text-ink-faint">Filters only apply to export types that support them.</p>
        </div>

        {error && <p role="alert" className="text-sm text-error">{error}</p>}

        <Button onClick={download} isLoading={busy}>
          <Download className="h-4 w-4" />
          Download Excel file
        </Button>
      </div>

      <aside className="card space-y-2 p-5 text-sm text-ink-soft">
        <h3 className="text-sm font-semibold text-ink">About exports</h3>
        <p>Exports are generated on request from live data and are not saved anywhere. Download again any time for a fresh copy.</p>
      </aside>
    </div>
  )
}
