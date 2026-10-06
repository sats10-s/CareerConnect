import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Lock, Pencil, Send, Users } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { toast } from '@/components/ui/Toast'
import { DetailItem, MetaBadge } from '@/components/common/Cells'
import { PostingFormModal } from './PostingFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { companyService } from '@/services/companyService'
import { parseApiError } from '@/utils/apiError'
import { formatDate } from '@/utils/dateFormat'
import { EMPLOYMENT_TYPE_LABEL, POSTING_REVIEW_STATE, WORK_MODE_LABEL } from '@/utils/statusMeta'

export interface PostingRef {
  type: 'job' | 'internship'
  id: number
}

const STATE_HINT: Record<string, string> = {
  draft: 'Only your team can see this draft. Submit it when it’s ready.',
  in_review: 'Waiting for the placement office to check eligibility and publish it. You can still edit it until then.',
  live: 'Published. Eligible students can see it and apply.',
  closed: 'Closed to new applications. Existing applicants stay in your list.',
  archived: 'Archived by the placement office.',
}

/** A recruiter's posting: details, review state, and edit / submit / close. */
export function PostingDrawer({ posting, onClose, onChanged }: { posting: PostingRef | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(
    () => companyService.postings.show(posting!.type, posting!.id),
    posting ? `${posting.type}-${posting.id}` : null,
  )
  const [editing, setEditing] = useState(false)
  const [confirm, setConfirm] = useState<'submit' | 'close' | null>(null)
  const [busy, setBusy] = useState(false)

  async function run() {
    if (!data || !confirm) return
    setBusy(true)
    try {
      if (confirm === 'submit') {
        const { message } = await companyService.postings.submit(data.type, data.id)
        toast('success', message || 'Submitted.')
      } else {
        await companyService.postings.close(data.type, data.id)
        toast('success', 'Posting closed to new applications.')
      }
      setConfirm(null)
      reload()
      onChanged()
    } catch (err) {
      toast('error', parseApiError(err, 'Unable to update the posting.').message)
      setConfirm(null)
    } finally {
      setBusy(false)
    }
  }

  const footer = data ? (
    <div className="flex flex-wrap justify-end gap-3">
      {data.applications_count > 0 && (
        <Link to={`/company/applicants?type=${data.type}&posting=${data.id}`} className="btn-secondary">
          <Users className="h-4 w-4" />
          Applicants ({data.applications_count})
        </Link>
      )}
      {data.status === 'draft' && (
        <Button variant="secondary" onClick={() => setEditing(true)}>
          <Pencil className="h-4 w-4" />
          Edit
        </Button>
      )}
      {data.review_state === 'draft' && (
        <Button onClick={() => setConfirm('submit')}>
          <Send className="h-4 w-4" />
          Submit
        </Button>
      )}
      {data.review_state === 'live' && (
        <Button variant="danger" onClick={() => setConfirm('close')}>
          <Lock className="h-4 w-4" />
          Close
        </Button>
      )}
    </div>
  ) : undefined

  return (
    <>
      <Drawer open={posting !== null} onClose={onClose} title={data?.title ?? 'Posting'} width="md" footer={footer}>
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-8 w-1/2" />
            <LoadingSkeleton className="h-24 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : data ? (
          <div className="space-y-6">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{data.type === 'job' ? 'Job' : 'Internship'}</p>
                <p className="mt-1 text-sm text-ink-soft">{STATE_HINT[data.review_state]}</p>
              </div>
              <MetaBadge map={POSTING_REVIEW_STATE} value={data.review_state} />
            </div>

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Location">{data.location}</DetailItem>
              <DetailItem label="Work mode">{data.work_mode ? WORK_MODE_LABEL[data.work_mode] : null}</DetailItem>
              {data.type === 'job' ? (
                <>
                  <DetailItem label="Employment type">{data.employment_type ? EMPLOYMENT_TYPE_LABEL[data.employment_type] : null}</DetailItem>
                  <DetailItem label="Salary">{data.salary_range}</DetailItem>
                </>
              ) : (
                <>
                  <DetailItem label="Duration">{data.duration_months ? `${data.duration_months} month${data.duration_months === 1 ? '' : 's'}` : null}</DetailItem>
                  <DetailItem label="Stipend">{data.stipend}</DetailItem>
                </>
              )}
              <DetailItem label="Deadline">{data.application_deadline ? formatDate(data.application_deadline) : null}</DetailItem>
              <DetailItem label="Openings">{data.openings ? String(data.openings) : null}</DetailItem>
              <DetailItem label="Applicants">{String(data.applications_count)}</DetailItem>
              <DetailItem label="Created">{formatDate(data.created_at)}</DetailItem>
            </dl>

            {data.description && <p className="whitespace-pre-line text-sm leading-relaxed text-ink-soft">{data.description}</p>}

            <section>
              <h4 className="mb-2 text-sm font-semibold text-ink">Eligibility</h4>
              {data.eligibility.enforced || data.eligibility.minimum_cgpa != null || (data.eligibility.departments?.length ?? 0) > 0 ? (
                <ul className="space-y-1 text-sm text-ink-soft">
                  {data.eligibility.minimum_cgpa != null && <li>Minimum CGPA {data.eligibility.minimum_cgpa}</li>}
                  {data.eligibility.maximum_backlogs != null && <li>At most {data.eligibility.maximum_backlogs} backlogs</li>}
                  {(data.eligibility.departments?.length ?? 0) > 0 && <li>{data.eligibility.departments?.map((d) => d.name).join(', ')}</li>}
                  {(data.eligibility.years?.length ?? 0) > 0 && <li>Year {data.eligibility.years?.join(', ')}</li>}
                </ul>
              ) : (
                <p className="text-sm text-ink-faint">Set by the placement office when it reviews the posting.</p>
              )}
            </section>
          </div>
        ) : null}
      </Drawer>

      {editing && data && <PostingFormModal existing={data} onClose={() => setEditing(false)} onSaved={() => { reload(); onChanged() }} />}
      {confirm && data && (
        <ConfirmDialog
          open
          onClose={() => !busy && setConfirm(null)}
          onConfirm={run}
          isConfirming={busy}
          tone={confirm === 'close' ? 'danger' : 'default'}
          title={confirm === 'submit' ? 'Submit this posting?' : 'Close this posting?'}
          confirmLabel={confirm === 'submit' ? 'Submit' : 'Close posting'}
          description={confirm === 'submit'
            ? 'The placement office checks eligibility and publishes it. If your institution doesn’t require review, it goes live straight away.'
            : 'Students can no longer apply. You keep every existing applicant.'}
        />
      )}
    </>
  )
}
