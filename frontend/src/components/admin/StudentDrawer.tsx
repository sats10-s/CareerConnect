import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Download, KeyRound } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { ProgressBar } from '@/components/ui/ProgressBar'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { toast } from '@/components/ui/Toast'
import { ApplicationBadge, InternshipCell, MetaBadge } from '@/components/common/Cells'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Modal } from '@/components/ui/Modal'
import { ProfileHeader } from '@/components/profile/ProfileHeader'
import { MyProfileCard } from '@/components/profile/MyProfileCard'
import { AcademicCard, AcademicHistoryCard, EducationCard, PersonalCard } from '@/components/profile/college/CollegeCards'
import { useAuth } from '@/hooks/useAuth'
import { superAdminService } from '@/services/superAdminService'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { parseApiError, parseBlobApiError } from '@/utils/apiError'
import { formatDate, formatFileSize } from '@/utils/dateFormat'
import { saveBlob } from '@/utils/downloadBlob'
import { ASSIGNMENT_STATUS } from '@/utils/statusMeta'

/**
 * GET /admin/students/{id}: the student's college record (same cards the
 * student sees, plus who changed what), their own profile fields, reported
 * corrections, every application and every internship assignment. Super
 * Admins can also reset the student to "mobile number + forced change".
 */
export function StudentDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => adminService.students.show(id as number), id)
  const [downloading, setDownloading] = useState(false)
  const { user } = useAuth()
  const isSuperAdmin = user?.role === 'SUPER_ADMIN'
  const [askReset, setAskReset] = useState(false)
  const [resetting, setResetting] = useState(false)
  const [oneTimePassword, setOneTimePassword] = useState<string | null>(null)
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

  async function resolve(correctionId: number) {
    if (!id) return
    try {
      await adminService.students.resolveCorrection(id, correctionId)
      reload()
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to update this request.').message)
    }
  }

  async function resetPassword() {
    if (!id) return
    setResetting(true)
    try {
      const result = await superAdminService.resetStudentPassword(id)
      toast('success', result.message)
      if (result.one_time_password) setOneTimePassword(result.one_time_password)
      setAskReset(false)
      reload()
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to reset the password.').message)
    } finally {
      setResetting(false)
    }
  }

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
            {!data.is_active && <StatusBadge tone="error">Account inactive</StatusBadge>}
            {data.applications.some((a) => a.status === 'SELECTED') && <StatusBadge tone="success">Placed</StatusBadge>}
          </div>

          <ProfileHeader profile={p} showCompletion={false} />

          {(data.must_change_password || isSuperAdmin) && (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-hairline px-4 py-3 text-sm">
              <span className="text-ink-soft">
                {data.must_change_password ? 'Has not set their own password yet (first-time password in use).' : `Signed in ${data.last_login_at ? formatDate(data.last_login_at) : 'never'}.`}
              </span>
              {isSuperAdmin && (
                <Button variant="secondary" onClick={() => setAskReset(true)}>
                  <KeyRound className="h-4 w-4" /> Reset password
                </Button>
              )}
            </div>
          )}

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <AcademicCard profile={p} />
            <EducationCard profile={p} />
            <PersonalCard profile={p} />
            <AcademicHistoryCard profile={p} showAuthor />
          </div>

          <MyProfileCard profile={p} />

          <div>
            <div className="mb-1.5 flex items-center justify-between text-xs">
              <span className="font-medium uppercase tracking-wide text-ink-faint">Profile completion</span>
              <span className="tabular-nums text-ink">{p.profile_completion}%</span>
            </div>
            <ProgressBar value={p.profile_completion} label="Profile completion" />
          </div>

          {p.correction_requests.length > 0 && (
            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">Corrections reported by the student</h4>
              <ul className="card divide-y divide-hairline">
                {p.correction_requests.map((c) => (
                  <li key={c.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3 text-sm">
                    <div className="min-w-0">
                      <p className="font-medium text-ink">{c.field_label} <span className="text-xs font-normal text-ink-faint">· {formatDate(c.created_at)}</span></p>
                      <p className="mt-0.5 whitespace-pre-line text-ink-soft">{c.note}</p>
                    </div>
                    {c.status === 'open' ? (
                      <Button variant="secondary" onClick={() => resolve(c.id)}>Mark resolved</Button>
                    ) : (
                      <StatusBadge tone="success">Resolved</StatusBadge>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          )}

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
      <ConfirmDialog
        open={askReset}
        onClose={() => setAskReset(false)}
        onConfirm={resetPassword}
        isConfirming={resetting}
        tone="danger"
        title="Reset this student's password?"
        confirmLabel="Reset password"
        description="Their password goes back to the registered mobile number (or a one-time password if there is none), they are signed out everywhere, and they must choose a new password at next sign-in."
      />
      <Modal open={oneTimePassword !== null} onClose={() => setOneTimePassword(null)} title="One-time password" footer={<Button onClick={() => setOneTimePassword(null)}>Done</Button>}>
        <p className="text-sm text-ink-soft">No valid mobile number is on record. Give the student this password privately - it will not be shown again.</p>
        <p className="mt-4 rounded-xl border border-hairline bg-sidebar px-4 py-3 text-center font-mono text-lg text-ink">{oneTimePassword}</p>
      </Modal>
    </Drawer>
  )
}
