import { useState } from 'react'
import { Download, FileText } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import { toast } from '@/components/ui/Toast'
import { DetailItem, InternshipCell, MetaBadge, PersonCell } from '@/components/common/Cells'
import { ReviewActions } from './ReviewActions'
import { useApiResource } from '@/hooks/useApiResource'
import { facultyService, type ReviewAction } from '@/services/facultyService'
import { parseApiError } from '@/utils/apiError'
import { formatDateTime, formatFileSize } from '@/utils/dateFormat'
import { saveBlob } from '@/utils/downloadBlob'
import { DOCUMENT_STATUS, DOCUMENT_TYPE_LABEL, metaFor } from '@/utils/statusMeta'

interface DocumentDrawerProps {
  documentId: number | null
  onClose: () => void
  onChanged: () => void
}

export function DocumentDrawer({ documentId, onClose, onChanged }: DocumentDrawerProps) {
  const { data, isLoading, error, reload } = useApiResource(() => facultyService.document(documentId as number), documentId)
  const [downloading, setDownloading] = useState(false)

  async function review(action: ReviewAction, remarks?: string) {
    await facultyService.reviewDocument(documentId as number, action, remarks)
    reload()
    onChanged()
  }

  async function download() {
    if (!data) return
    setDownloading(true)
    try {
      saveBlob(await facultyService.downloadDocument(data.id), data.original_filename)
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to download this file.').message)
    } finally {
      setDownloading(false)
    }
  }

  const history: TimelineItem[] = (data?.history ?? []).map((entry, index, all) => ({
    id: entry.id,
    title: `${entry.from_status ? `${metaFor(DOCUMENT_STATUS, entry.from_status).label} → ` : ''}${metaFor(DOCUMENT_STATUS, entry.to_status).label}`,
    description: entry.remarks ?? undefined,
    timestamp: `${formatDateTime(entry.created_at)}${entry.changed_by ? ` · ${entry.changed_by.name}` : ''}`,
    status: index === all.length - 1 ? 'current' : 'completed',
  }))

  return (
    <Drawer open={documentId !== null} onClose={onClose} title={data?.title ?? 'Document'} width="lg">
      {isLoading && !data ? (
        <div className="space-y-4" aria-busy="true">
          <LoadingSkeleton className="h-10 w-2/3" />
          <LoadingSkeleton className="h-4 w-full" />
          <LoadingSkeleton className="h-16 w-full" />
        </div>
      ) : error ? (
        <ErrorState description={error} onRetry={reload} />
      ) : data ? (
        <div className="space-y-6">
          <div className="flex items-start justify-between gap-3">
            <PersonCell student={data.student} />
            <MetaBadge map={DOCUMENT_STATUS} value={data.status} />
          </div>
          <div className="rounded-xl border border-hairline p-3">
            <InternshipCell internship={data.internship} />
          </div>

          <div className="flex items-center gap-3 rounded-xl border border-hairline p-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-tint text-primary">
              <FileText className="h-5 w-5" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-ink">{data.original_filename}</p>
              <p className="text-xs text-ink-faint">{DOCUMENT_TYPE_LABEL[data.document_type] ?? data.document_type} · {formatFileSize(data.file_size)}</p>
            </div>
            <Button variant="secondary" onClick={download} isLoading={downloading}>
              <Download className="h-4 w-4" />
              Download
            </Button>
          </div>

          <dl className="grid grid-cols-2 gap-4">
            <DetailItem label="Uploaded by">{data.uploaded_by?.name}</DetailItem>
            <DetailItem label="Submitted">{formatDateTime(data.submitted_at)}</DetailItem>
          </dl>

          <section>
            <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Student notes</h3>
            {data.student_notes ? <p className="whitespace-pre-line text-sm text-ink">{data.student_notes}</p> : <p className="text-sm text-ink-faint">No notes added</p>}
          </section>

          {data.faculty_review_remarks && (
            <section className="rounded-xl bg-sidebar p-3">
              <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Your last remarks</h3>
              <p className="whitespace-pre-line text-sm text-ink">{data.faculty_review_remarks}</p>
            </section>
          )}

          <section className="border-t border-hairline pt-5">
            <h3 className="mb-3 text-sm font-semibold text-ink">Review</h3>
            <ReviewActions key={data.status} status={data.status} noun="document" onReview={review} />
          </section>

          {history.length > 0 && (
            <section>
              <h3 className="mb-3 text-sm font-semibold text-ink">History</h3>
              <Timeline items={history} />
            </section>
          )}
        </div>
      ) : null}
    </Drawer>
  )
}
