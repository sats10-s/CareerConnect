import { useRef, useState } from 'react'
import { Download, FileText, Trash2, Upload } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { studentProfileService } from '@/services/studentProfileService'
import type { StudentProfileData } from '@/types/studentProfile'
import { describeFailure, ErrorBanner, SuccessBanner, formatBytes, formatDate } from './shared'

const MAX_BYTES = 5 * 1024 * 1024
const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx']

/**
 * Resume upload/replace/download/delete. There is no public URL for
 * the file anywhere in this component - download always goes through
 * the authenticated, ownership-scoped GET /student/profile/resume
 * endpoint, streamed as a Blob and handed to the browser via a
 * throwaway object URL (never a direct href to a storage path).
 */
export function ResumeSection({
  profile,
  onProfileChange,
}: {
  profile: StudentProfileData
  onProfileChange: (profile: StudentProfileData) => void
}) {
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [isUploading, setIsUploading] = useState(false)
  const [isDownloading, setIsDownloading] = useState(false)
  const [isDeleting, setIsDeleting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)

  function validateClientSide(file: File): string | null {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
    if (!ALLOWED_EXTENSIONS.includes(extension)) {
      return 'Only PDF, DOC, or DOCX files are accepted.'
    }
    if (file.size > MAX_BYTES) {
      return 'File is too large - the maximum size is 5 MB.'
    }
    return null
  }

  async function handleFileSelected(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    setError(null)
    setSuccess(null)

    const clientError = validateClientSide(file)
    if (clientError) {
      setError(clientError)
      return
    }

    setIsUploading(true)
    try {
      const updated = await studentProfileService.uploadResume(file)
      onProfileChange(updated)
      setSuccess(profile.resume ? 'Resume replaced successfully.' : 'Resume uploaded successfully.')
    } catch (err) {
      const failure = describeFailure(err, 'Unable to upload your resume. Please try again.')
      setError(failure.errors.resume?.[0] ?? failure.message)
    } finally {
      setIsUploading(false)
    }
  }

  async function handleDownload() {
    setError(null)
    setIsDownloading(true)
    try {
      const blob = await studentProfileService.downloadResume()
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = profile.resume?.original_name ?? 'resume'
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } catch {
      setError('Unable to download your resume. Please try again.')
    } finally {
      setIsDownloading(false)
    }
  }

  async function handleDelete() {
    if (!window.confirm('Delete your resume? This cannot be undone.')) return
    setError(null)
    setSuccess(null)
    setIsDeleting(true)
    try {
      const updated = await studentProfileService.deleteResume()
      onProfileChange(updated)
      setSuccess('Resume deleted.')
    } catch {
      setError('Unable to delete your resume. Please try again.')
    } finally {
      setIsDeleting(false)
    }
  }

  return (
    <Card>
      <CardHeader title="Resume" subtitle="PDF, DOC, or DOCX - up to 5 MB" />

      <div className="space-y-4 px-5 py-5">
        {error && <ErrorBanner message={error} />}
        {success && <SuccessBanner message={success} />}

        {profile.resume ? (
          <div className="flex items-center justify-between gap-4 rounded-lg border border-border bg-sidebar px-4 py-3">
            <div className="flex min-w-0 items-center gap-3">
              <FileText className="h-5 w-5 shrink-0 text-indigo" aria-hidden="true" />
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-ink">{profile.resume.original_name}</p>
                <p className="text-xs text-ink-faint">
                  {formatBytes(profile.resume.size)} · Uploaded {formatDate(profile.resume.uploaded_at.slice(0, 10))}
                </p>
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              <Button type="button" variant="secondary" onClick={handleDownload} isLoading={isDownloading}>
                <Download className="h-4 w-4" />
                Download
              </Button>
              <Button type="button" variant="secondary" onClick={handleDelete} isLoading={isDeleting}>
                <Trash2 className="h-4 w-4" />
                Delete
              </Button>
            </div>
          </div>
        ) : (
          <p className="text-sm text-ink-faint">No resume uploaded yet.</p>
        )}

        <div>
          <input
            ref={fileInputRef}
            type="file"
            accept=".pdf,.doc,.docx"
            className="hidden"
            onChange={handleFileSelected}
          />
          <Button type="button" variant="secondary" onClick={() => fileInputRef.current?.click()} isLoading={isUploading}>
            <Upload className="h-4 w-4" />
            {profile.resume ? 'Replace resume' : 'Upload resume'}
          </Button>
        </div>
      </div>
    </Card>
  )
}
