import { useEffect, useState } from 'react'
import { isAxiosError } from 'axios'
import { AlertTriangle, Download, FolderOpen, Plus } from 'lucide-react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { Modal } from '@/components/ui/Modal'
import { FileUpload } from '@/components/ui/FileUpload'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { toast } from '@/components/ui/Toast'
import { internshipDocumentService } from '@/services/internshipDocumentService'
import type { InternshipDocumentDetail, InternshipDocumentListItem, InternshipDocumentStatus, InternshipDocumentType } from '@/types/internshipDocument'

const DOCUMENT_TYPE_OPTIONS: { value: InternshipDocumentType; label: string }[] = [
  { value: 'OFFER_LETTER', label: 'Offer letter' },
  { value: 'JOINING_LETTER', label: 'Joining letter' },
  { value: 'WORK_EVIDENCE', label: 'Work evidence' },
  { value: 'FINAL_REPORT', label: 'Final report' },
  { value: 'INTERNSHIP_CERTIFICATE', label: 'Internship certificate' },
  { value: 'COMPLETION_CERTIFICATE', label: 'Completion certificate' },
  { value: 'OTHER', label: 'Other' },
]

const STATUS_TONE: Record<InternshipDocumentStatus, 'info' | 'warning' | 'success'> = {
  SUBMITTED: 'info',
  UNDER_REVIEW: 'info',
  CHANGES_REQUESTED: 'warning',
  APPROVED: 'success',
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function extractMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const message = (error.response?.data as { message?: string } | undefined)?.message
    if (message) return message
  }
  return fallback
}

export function DocumentsSection({ assignmentId }: { assignmentId: number }) {
  const [documents, setDocuments] = useState<InternshipDocumentListItem[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)
  const [downloadingId, setDownloadingId] = useState<number | null>(null)

  const [isUploading, setIsUploading] = useState(false)
  const [uploadError, setUploadError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [documentType, setDocumentType] = useState<InternshipDocumentType>('WORK_EVIDENCE')
  const [title, setTitle] = useState('')
  const [notes, setNotes] = useState('')
  const [file, setFile] = useState<File | null>(null)

  const [selected, setSelected] = useState<InternshipDocumentListItem | null>(null)
  const [detail, setDetail] = useState<InternshipDocumentDetail | null>(null)
  const [isLoadingDetail, setIsLoadingDetail] = useState(false)
  const [resubmitFile, setResubmitFile] = useState<File | null>(null)
  const [resubmitNotes, setResubmitNotes] = useState('')
  const [isResubmitting, setIsResubmitting] = useState(false)
  const [resubmitError, setResubmitError] = useState<string | null>(null)

  function load() {
    setIsLoading(true)
    setLoadError(false)
    internshipDocumentService
      .forAssignment(assignmentId)
      .then(setDocuments)
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }

  useEffect(load, [assignmentId])

  useEffect(() => {
    if (!selected) {
      setDetail(null)
      return
    }
    setIsLoadingDetail(true)
    setResubmitFile(null)
    setResubmitNotes('')
    setResubmitError(null)
    internshipDocumentService
      .show(selected.id)
      .then(setDetail)
      .finally(() => setIsLoadingDetail(false))
  }, [selected])

  function openUpload() {
    setDocumentType('WORK_EVIDENCE')
    setTitle('')
    setNotes('')
    setFile(null)
    setUploadError(null)
    setIsUploading(true)
  }

  function handleUpload() {
    if (!file || !title.trim()) return
    setIsSaving(true)
    setUploadError(null)
    internshipDocumentService
      .upload({ internship_assignment_id: assignmentId, document_type: documentType, title: title.trim(), file, student_notes: notes.trim() || undefined })
      .then(() => {
        toast('success', 'Document uploaded.')
        setIsUploading(false)
        load()
      })
      .catch((error) => setUploadError(extractMessage(error, "Couldn't upload this document. Please try again.")))
      .finally(() => setIsSaving(false))
  }

  function handleDownload(doc: InternshipDocumentListItem) {
    setDownloadingId(doc.id)
    internshipDocumentService
      .download(doc.id)
      .then((blob) => {
        const url = window.URL.createObjectURL(blob)
        const link = window.document.createElement('a')
        link.href = url
        link.download = doc.original_filename
        window.document.body.appendChild(link)
        link.click()
        link.remove()
        window.URL.revokeObjectURL(url)
      })
      .catch(() => toast('error', "Couldn't download this document."))
      .finally(() => setDownloadingId(null))
  }

  function handleResubmit() {
    if (!detail || !resubmitFile) return
    setIsResubmitting(true)
    setResubmitError(null)
    internshipDocumentService
      .resubmit(detail.id, resubmitFile, resubmitNotes.trim() || undefined)
      .then(() => {
        toast('success', 'Document resubmitted.')
        setSelected(null)
        load()
      })
      .catch((error) => setResubmitError(extractMessage(error, "Couldn't resubmit this document.")))
      .finally(() => setIsResubmitting(false))
  }

  return (
    <section className="space-y-4">
      <SectionHeader
        title="Documents"
        action={
          <Button variant="secondary" onClick={openUpload}>
            <Plus className="h-4 w-4" />
            Upload
          </Button>
        }
      />

      {isLoading ? (
        <LoadingSkeletonLines count={3} className="h-14 w-full" />
      ) : loadError ? (
        <div className="card">
          <EmptyState
            icon={AlertTriangle}
            title="Couldn't load documents"
            description="Something went wrong reaching the server."
            action={
              <Button variant="secondary" onClick={load}>
                Retry
              </Button>
            }
          />
        </div>
      ) : documents.length === 0 ? (
        <div className="card">
          <EmptyState icon={FolderOpen} title="No documents uploaded yet" description="Upload your offer letter, work evidence, or final report here." />
        </div>
      ) : (
        <div className="card divide-y divide-hairline">
          {documents.map((doc) => (
            <div key={doc.id} className="flex items-center justify-between gap-4 px-5 py-3">
              <button type="button" onClick={() => setSelected(doc)} className="min-w-0 flex-1 text-left">
                <p className="truncate text-sm font-medium text-ink">{doc.title}</p>
                <p className="truncate text-xs text-ink-faint">
                  {doc.original_filename} · {formatBytes(doc.file_size)}
                </p>
              </button>
              <StatusBadge tone={STATUS_TONE[doc.status]}>{doc.status.replace('_', ' ')}</StatusBadge>
              <Button variant="secondary" onClick={() => handleDownload(doc)} isLoading={downloadingId === doc.id}>
                <Download className="h-4 w-4" />
              </Button>
            </div>
          ))}
        </div>
      )}

      <Modal open={isUploading} onClose={() => setIsUploading(false)} title="Upload document">
        <div className="space-y-4">
          {uploadError && <p className="rounded-lg bg-error-bg p-3 text-sm text-error">{uploadError}</p>}
          <Select value={documentType} onChange={(event) => setDocumentType(event.target.value as InternshipDocumentType)} aria-label="Document type">
            {DOCUMENT_TYPE_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
          <Input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Title" />
          <FileUpload accept=".pdf,.doc,.docx" helperText="PDF, DOC, or DOCX - up to 5 MB" selectedFile={file} onFileSelected={setFile} />
          <Textarea value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Notes for your coordinator (optional)" rows={2} />
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setIsUploading(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button onClick={handleUpload} isLoading={isSaving} disabled={!file || !title.trim()}>
            Upload
          </Button>
        </div>
      </Modal>

      <Modal open={selected !== null} onClose={() => setSelected(null)} title={selected?.title ?? 'Document'}>
        {isLoadingDetail || !detail ? (
          <LoadingSkeletonLines count={3} className="h-4 w-full" />
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-2">
              <StatusBadge tone={STATUS_TONE[detail.status]}>{detail.status.replace('_', ' ')}</StatusBadge>
              <span className="text-xs text-ink-faint">{detail.original_filename}</span>
            </div>
            {detail.student_notes && <p className="text-sm text-ink-soft">{detail.student_notes}</p>}

            {detail.status === 'CHANGES_REQUESTED' && (
              <>
                {detail.faculty_review_remarks && (
                  <div className="rounded-lg bg-warning-bg p-3 text-sm text-warning">
                    <p className="font-medium">Changes requested:</p>
                    <p>{detail.faculty_review_remarks}</p>
                  </div>
                )}
                {resubmitError && <p className="rounded-lg bg-error-bg p-3 text-sm text-error">{resubmitError}</p>}
                <FileUpload accept=".pdf,.doc,.docx" helperText="PDF, DOC, or DOCX - up to 5 MB" selectedFile={resubmitFile} onFileSelected={setResubmitFile} />
                <Textarea value={resubmitNotes} onChange={(event) => setResubmitNotes(event.target.value)} placeholder="Notes (optional)" rows={2} />
                <div className="flex justify-end">
                  <Button onClick={handleResubmit} isLoading={isResubmitting} disabled={!resubmitFile}>
                    Resubmit
                  </Button>
                </div>
              </>
            )}
            {detail.status === 'APPROVED' && detail.faculty_review_remarks && (
              <div className="rounded-lg bg-success-bg p-3 text-sm text-success">{detail.faculty_review_remarks}</div>
            )}
          </div>
        )}
      </Modal>
    </section>
  )
}
