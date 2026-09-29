import { useState } from 'react'
import { Download, Upload } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { FileUpload } from '@/components/ui/FileUpload'
import { Select } from '@/components/ui/Select'
import { toast } from '@/components/ui/Toast'
import { adminService } from '@/services/adminService'
import { parseApiError, parseBlobApiError } from '@/utils/apiError'
import { saveBlob } from '@/utils/downloadBlob'
import { EXCEL_IMPORT_TYPES } from '@/utils/statusMeta'
import type { ExcelImportType } from '@/types/admin'

const MAX_BYTES = 10 * 1024 * 1024

/**
 * Upload step of the Excel Sync Center: pick what the file contains,
 * optionally grab the official template, upload. Nothing is applied
 * here - the API validates the file into a batch you review and
 * confirm from History (POST /admin/excel/import only stages it).
 */
export function ExcelImportTab({ onUploaded }: { onUploaded: (batchId: number) => void }) {
  const [type, setType] = useState<ExcelImportType>('PLACEMENT_STATUS')
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const current = EXCEL_IMPORT_TYPES.find((t) => t.value === type)

  function pick(next: File | null) {
    setError(null)
    if (next) {
      if (!/\.(xlsx|xls)$/i.test(next.name)) {
        setError('Choose an Excel file (.xlsx or .xls).')
        return
      }
      if (next.size > MAX_BYTES) {
        setError('That file is larger than 10 MB. Split it into smaller files and upload them one at a time.')
        return
      }
    }
    setFile(next)
  }

  async function downloadTemplate() {
    setDownloading(true)
    try {
      saveBlob(await adminService.excel.template(type), `${type.toLowerCase()}_template.xlsx`)
    } catch (err) {
      toast('error', (await parseBlobApiError(err, 'Unable to download the template.')).message)
    } finally {
      setDownloading(false)
    }
  }

  async function upload() {
    if (!file) {
      setError('Choose the Excel file to upload.')
      return
    }
    setUploading(true)
    setError(null)
    try {
      const batch = await adminService.excel.upload(type, file)
      toast(batch.status === 'FAILED' ? 'error' : 'success', batch.status === 'FAILED' ? 'The file was rejected - see why in the import history.' : 'File checked. Review it before anything is applied.')
      setFile(null)
      onUploaded(batch.id)
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to upload this file.')
      setError(parsed.fieldErrors.file ?? parsed.fieldErrors.type ?? parsed.message)
    } finally {
      setUploading(false)
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <div className="card space-y-5 p-5">
        <div>
          <label className="label" htmlFor="xl-type">What is in the file?</label>
          <Select id="xl-type" value={type} onChange={(e) => setType(e.target.value as ExcelImportType)}>
            {EXCEL_IMPORT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
          {current && <p className="mt-1.5 text-xs text-ink-faint">{current.hint}</p>}
        </div>

        <div>
          <p className="label">Excel file</p>
          <FileUpload accept=".xlsx,.xls" selectedFile={file} onFileSelected={pick} disabled={uploading} helperText="Excel (.xlsx or .xls), up to 10 MB" />
          {error && <p role="alert" className="mt-2 text-sm text-error">{error}</p>}
        </div>

        <div className="flex flex-wrap gap-2">
          <Button onClick={upload} isLoading={uploading}>
            <Upload className="h-4 w-4" />
            Upload and check
          </Button>
          <Button variant="secondary" onClick={downloadTemplate} isLoading={downloading}>
            <Download className="h-4 w-4" />
            Download template
          </Button>
        </div>
      </div>

      <aside className="card space-y-3 p-5 text-sm text-ink-soft">
        <h3 className="text-sm font-semibold text-ink">How importing works</h3>
        <ol className="list-decimal space-y-2 pl-5">
          <li>Start from the official template for the type you're importing - columns marked * are required.</li>
          <li>Upload it. Every row is matched and checked, but <strong className="text-ink">nothing is changed yet</strong>.</li>
          <li>Review the preview in History, then confirm. Rows with errors are skipped.</li>
          <li>A confirmed import can be rolled back if nothing has changed since.</li>
        </ol>
      </aside>
    </div>
  )
}
