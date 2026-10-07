import { useState } from 'react'
import { Download } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
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
// "Shortlisted"/"Interviewed" mean ever reached that stage, even if the student later moved on.
const STUDENT_STATUSES = [
  { value: 'applied', label: 'Applied' },
  { value: 'not_applied', label: 'Not applied' },
  { value: 'shortlisted', label: 'Shortlisted' },
  { value: 'not_shortlisted', label: 'Never shortlisted' },
  { value: 'interviewed', label: 'Interviewed' },
  { value: 'not_interviewed', label: 'Never interviewed' },
  { value: 'placed', label: 'Placed' },
  { value: 'unplaced', label: 'Not placed' },
]
const APPLICATION_STATUSES = ['APPLIED', 'UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'INTERVIEW', 'SELECTED', 'REJECTED', 'WITHDRAWN']
  .map((x) => ({ value: x, label: x.charAt(0) + x.slice(1).toLowerCase().replace('_', ' ') }))
const STUDENT_STATUS_TYPES = ['STUDENTS', 'STUDENT_APPLICATION_REPORT']
const BACKLOG_OPTIONS = [
  { value: '0', label: 'No backlogs' },
  { value: 'max1', label: 'Up to 1 backlog' },
  { value: 'max2', label: 'Up to 2 backlogs' },
  { value: 'some', label: '1-2 backlogs' },
  { value: 'many', label: '3+ backlogs' },
  { value: 'not_reported', label: 'Backlogs not reported' },
]
// Exports with nothing student-level to filter on.
const NO_STUDENT_FILTERS = ['PLACEMENT_DRIVES', 'COMPANIES']

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
  const [studentStatus, setStudentStatus] = useState('')
  const [applicationStatus, setApplicationStatus] = useState('')
  const [cgpaMin, setCgpaMin] = useState('')
  const [backlogs, setBacklogs] = useState('')
  const [target, setTarget] = useState('')
  const [applicableType, setApplicableType] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const departments = useApiResource(() => metaService.departments(), 'departments')
  const batches = useApiResource(() => metaService.batches(), 'batches')
  const isEligible = type === 'ELIGIBLE_STUDENTS'
  const targets = useApiResource(async () => {
    if (!isEligible) return []
    const [jobs, internships, drives] = await Promise.all([
      adminService.jobs.list({ per_page: 100 }),
      adminService.internships.list({ per_page: 100 }),
      adminService.drives.list({ per_page: 100 }),
    ])
    return [
      ...drives.items.map((d) => ({ value: `drive_id:${d.id}`, label: `Drive: ${d.title}${d.company ? ` (${d.company.name})` : ''}` })),
      ...jobs.items.map((j) => ({ value: `job_id:${j.id}`, label: `Job: ${j.company?.name ?? ''} ${j.title}`.trim() })),
      ...internships.items.map((i) => ({ value: `internship_id:${i.id}`, label: `Internship: ${i.company?.name ?? ''} ${i.title}`.trim() })),
    ]
  }, `export-targets:${isEligible}`)

  async function download() {
    if (dateFrom && dateTo && dateTo < dateFrom) {
      setError('The end date must be on or after the start date.')
      return
    }
    if (isEligible && !target) {
      setError('Choose the drive, job or internship to check eligibility against.')
      return
    }
    if (cgpaMin && (Number.isNaN(Number(cgpaMin)) || Number(cgpaMin) < 0 || Number(cgpaMin) > 10)) {
      setError('Minimum CGPA must be between 0 and 10.')
      return
    }
    setError(null)
    setBusy(true)
    const filters: ExportFilters = {}
    if (departmentId) filters.department_id = Number(departmentId)
    if (batchId) filters.batch_id = Number(batchId)
    if (year) filters.year = Number(year)
    if (section) filters.section = section
    if (studentStatus && STUDENT_STATUS_TYPES.includes(type)) filters.student_status = studentStatus
    if (applicationStatus && type === 'APPLICATIONS') filters.status = applicationStatus
    if (cgpaMin) filters.cgpa_min = Number(cgpaMin)
    if (backlogs === '0' || backlogs.startsWith('max')) filters.max_backlogs = Number(backlogs.replace('max', ''))
    else if (backlogs) filters.backlogs = backlogs as 'some' | 'many' | 'not_reported'
    if (isEligible && target) {
      const [key, id] = target.split(':')
      filters[key as 'job_id' | 'internship_id' | 'drive_id'] = Number(id)
    }
    if (NO_STUDENT_FILTERS.includes(type)) {
      for (const key of ['department_id', 'batch_id', 'year', 'section', 'cgpa_min', 'max_backlogs', 'backlogs'] as const) delete filters[key]
    }    if (applicableType) filters.applicable_type = applicableType as 'job' | 'internship'
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
          {isEligible && (
            <div className="mb-3">
              <Select aria-label="Drive, job or internship" value={target} onChange={(e) => setTarget(e.target.value)}>
                <option value="">{targets.isLoading ? 'Loading drives, jobs and internships…' : 'Choose a drive, job or internship'}</option>
                {(targets.data ?? []).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
              </Select>
            </div>
          )}
          <div className="flex flex-wrap items-center gap-3">
            <FilterSelect label="Department" value={departmentId} onChange={setDepartmentId} options={(departments.data ?? []).map((d) => ({ value: d.id, label: d.name }))} allLabel="All departments" />
            <FilterSelect label="Batch" value={batchId} onChange={setBatchId} options={(batches.data ?? []).map((b) => ({ value: b.id, label: b.name }))} allLabel="All batches" />
            <FilterSelect label="Year" value={year} onChange={setYear} options={YEARS} allLabel="All years" />
            <FilterSelect label="Section" value={section} onChange={setSection} options={SECTIONS} allLabel="All sections" />
            <div className="w-[calc(50%-0.375rem)] sm:w-44">
              <Input aria-label="Minimum CGPA" type="number" inputMode="decimal" min={0} max={10} step="0.1" placeholder="Min CGPA" value={cgpaMin} onChange={(e) => setCgpaMin(e.target.value)} />
            </div>
            <FilterSelect label="Backlogs" value={backlogs} onChange={setBacklogs} options={BACKLOG_OPTIONS} allLabel="Any backlogs" />
            {STUDENT_STATUS_TYPES.includes(type) && (
              <FilterSelect label="Student status" value={studentStatus} onChange={setStudentStatus} options={STUDENT_STATUSES} allLabel="All students" />
            )}
            {type === 'APPLICATIONS' && (
              <FilterSelect label="Application status" value={applicationStatus} onChange={setApplicationStatus} options={APPLICATION_STATUSES} allLabel="Any status" />
            )}
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
          <p className="mt-1.5 text-xs text-ink-faint">Filters only apply to export types that support them. Students with no CGPA yet are left out by a minimum CGPA, and blank backlogs only match "Backlogs not reported".</p>
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
