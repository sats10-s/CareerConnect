import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Download, ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { toast } from '@/components/ui/Toast'
import { ApplicationBadge, DetailItem, InternshipCell, MetaBadge } from '@/components/common/Cells'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { parseBlobApiError } from '@/utils/apiError'
import { formatDate, formatFileSize } from '@/utils/dateFormat'
import { saveBlob } from '@/utils/downloadBlob'
import { ASSIGNMENT_STATUS } from '@/utils/statusMeta'

/** GET /admin/students/{id}: the student's profile, every application and every internship assignment. Read-only. */
export function StudentDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => adminService.students.show(id as number), id)
  const [downloading, setDownloading] = useState(false)
  const p = data?.profile

  async function downloadResume() {
    if (!id || !p?.resume) return
    setDownloading(true)
    try {
      saveBlob(await adminService.students.resume(id), p.resume.original_name || 'resume')
    } catch (err) {
      toast('error', (await parseBlobApiError(err, 'Unable to download the resume.')).message)
    } finally {
      setDownloading(false)
    }
  }

  const links = p ? ([['LinkedIn', p.linkedin_url], ['GitHub', p.github_url], ['Portfolio', p.portfolio_url]] as const).filter(([, url]) => url) : []

  return (
    <Drawer open={id !== null} onClose={onClose} title={p?.name ?? 'Student'} width="lg">
      {isLoading && !data ? (
        <div className="space-y-4" aria-busy="true">
          <LoadingSkeleton className="h-10 w-2/3" />
          <LoadingSkeleton className="h-24 w-full" />
          <LoadingSkeleton className="h-32 w-full" />
        </div>
      ) : error ? (
        <ErrorState description={error} onRetry={reload} />
      ) : data && p ? (
        <div className="space-y-7">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-ink-soft">{p.roll_number}</span>
            {!data.is_active && <StatusBadge tone="error">Account inactive</StatusBadge>}
            {data.applications.some((a) => a.status === 'SELECTED') && <StatusBadge tone="success">Placed</StatusBadge>}
          </div>

          <dl className="grid grid-cols-2 gap-4">
            <DetailItem label="College email">{p.college_email}</DetailItem>
            <DetailItem label="Phone">{p.phone}</DetailItem>
            <DetailItem label="Personal email">{p.personal_email}</DetailItem>
            <DetailItem label="Department">{p.department}</DetailItem>
            <DetailItem label="Batch">{p.batch}</DetailItem>
            <DetailItem label="Year">{p.year ? `Year ${p.year}` : null}</DetailItem>
            <DetailItem label="CGPA">{p.cgpa !== null ? p.cgpa.toFixed(2) : null}</DetailItem>
            <DetailItem label="Backlogs">{p.backlogs !== null ? String(p.backlogs) : null}</DetailItem>
          </dl>

          <div>
            <div className="mb-1.5 flex items-center justify-between text-xs">
              <span className="font-medium uppercase tracking-wide text-ink-faint">Profile completion</span>
              <span className="tabular-nums text-ink">{p.profile_completion}%</span>
            </div>
            <ProgressBar value={p.profile_completion} label="Profile completion" />
          </div>

          <section className="space-y-2">
            <h4 className="text-sm font-semibold text-ink">Resume</h4>
            {p.resume ? (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-hairline p-3">
                <div className="min-w-0">
                  <p className="truncate text-sm text-ink">{p.resume.original_name}</p>
                  <p className="text-xs text-ink-faint">{formatFileSize(p.resume.size)} · uploaded {formatDate(p.resume.uploaded_at)}</p>
                </div>
                <Button variant="secondary" onClick={downloadResume} isLoading={downloading}>
                  <Download className="h-4 w-4" />
                  Download
                </Button>
              </div>
            ) : (
              <p className="text-sm text-ink-faint">No resume uploaded yet.</p>
            )}
          </section>

          {(p.skills.length > 0 || links.length > 0) && (
            <section className="space-y-3">
              {p.skills.length > 0 && (
                <div>
                  <h4 className="mb-2 text-sm font-semibold text-ink">Skills</h4>
                  <div className="flex flex-wrap gap-2">
                    {p.skills.map((s) => <span key={s.id} className="rounded-full border border-hairline px-3 py-1 text-xs text-ink-soft">{s.skill_name}</span>)}
                  </div>
                </div>
              )}
              {links.length > 0 && (
                <div className="flex flex-wrap gap-4">
                  {links.map(([label, url]) => (
                    <a key={label} href={url as string} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                      {label}
                      <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                  ))}
                </div>
              )}
            </section>
          )}

          <section>
            <h4 className="mb-3 text-sm font-semibold text-ink">Applications ({data.applications.length})</h4>
            {data.applications.length === 0 ? (
              <p className="text-sm text-ink-faint">This student hasn't applied to anything yet.</p>
            ) : (
              <ul className="card divide-y divide-hairline">
                {data.applications.map((a) => (
                  <li key={a.id} className="flex items-center justify-between gap-3 px-4 py-3">
                    <div className="min-w-0">
                      <InternshipCell internship={a.opportunity} />
                      <p className="mt-1 pl-12 text-xs text-ink-faint">{a.opportunity?.type === 'internship' ? 'Internship' : 'Job'} · applied {formatDate(a.applied_at)}</p>
                    </div>
                    <ApplicationBadge status={a.status} />
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section>
            <h4 className="mb-3 text-sm font-semibold text-ink">Internship assignments</h4>
            {data.internship_assignments.length === 0 ? (
              <p className="text-sm text-ink-faint">No internship assignment. Assign a coordinator once an internship application is selected.</p>
            ) : (
              <ul className="card divide-y divide-hairline">
                {data.internship_assignments.map((a) => (
                  <li key={a.id} className="flex items-center justify-between gap-3 px-4 py-3">
                    <div className="min-w-0">
                      <InternshipCell internship={a.internship} />
                      <p className="mt-1 pl-12 text-xs text-ink-faint">Coordinator: {a.faculty?.name ?? '—'}{a.started_at ? ` · started ${formatDate(a.started_at)}` : ''}</p>
                    </div>
                    <MetaBadge map={ASSIGNMENT_STATUS} value={a.status} />
                  </li>
                ))}
              </ul>
            )}
            {data.internship_assignments.length > 0 && (
              <Link to="/admin/internships?tab=assignments" className="mt-3 inline-block text-sm font-medium text-primary hover:underline">Manage assignments</Link>
            )}
          </section>
        </div>
      ) : null}
    </Drawer>
  )
}
