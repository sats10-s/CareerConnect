import { useState } from 'react'
import { Pencil } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { toast } from '@/components/ui/Toast'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { DetailItem, MetaBadge } from '@/components/common/Cells'
import { OpportunityFormModal } from './OpportunityFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { parseApiError } from '@/utils/apiError'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import { EMPLOYMENT_TYPE_LABEL, OPPORTUNITY_STATUS, OPPORTUNITY_TRANSITIONS, WORK_MODE_LABEL } from '@/utils/statusMeta'
import type { OpportunityLifecycle } from '@/types/admin'

const ACTION_LABEL: Record<string, string> = { published: 'Publish', closed: 'Close applications', archived: 'Archive' }
const ACTION_HELP: Record<string, string> = {
  published: 'Students will be able to see and apply to this posting.',
  closed: 'The posting stays visible but stops accepting applications.',
  archived: 'The posting is retired and hidden. This cannot be undone.',
}

interface OpportunityDrawerProps {
  kind: 'job' | 'internship'
  id: number | null
  onClose: () => void
  onChanged: () => void
}

export function OpportunityDrawer({ kind, id, onClose, onChanged }: OpportunityDrawerProps) {
  const api = kind === 'job' ? adminService.jobs : adminService.internships
  const { data, isLoading, error, reload } = useApiResource(() => api.show(id as number), id)
  const [editing, setEditing] = useState(false)
  const [pending, setPending] = useState<OpportunityLifecycle | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  async function transition() {
    if (!pending || !data) return
    setBusy(true)
    setActionError(null)
    try {
      await api.update(data.id, { status: pending })
      toast('success', pending === 'published' ? 'Published.' : pending === 'closed' ? 'Applications closed.' : 'Archived.')
      setPending(null)
      reload()
      onChanged()
    } catch (err) {
      setPending(null)
      setActionError(parseApiError(err, 'Unable to change the status.').message)
    } finally {
      setBusy(false)
    }
  }

  const next = data ? OPPORTUNITY_TRANSITIONS[data.status] ?? [] : []
  const e = data?.eligibility

  return (
    <>
      <Drawer open={id !== null} onClose={onClose} title={kind === 'job' ? 'Job posting' : 'Internship'} width="lg">
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-12 w-2/3" />
            <LoadingSkeleton className="h-4 w-full" />
            <LoadingSkeleton className="h-4 w-5/6" />
            <LoadingSkeleton className="h-24 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : data ? (
          <div className="space-y-6">
            <div className="flex items-start gap-3">
              <CompanyLogo name={data.company?.name} logoPath={data.company?.logo_path} />
              <div className="min-w-0 flex-1">
                <h3 className="font-display text-lg font-semibold text-ink">{data.title}</h3>
                <p className="text-sm text-ink-soft">{data.company?.name}{data.company?.industry ? ` · ${data.company.industry}` : ''}</p>
              </div>
              <MetaBadge map={OPPORTUNITY_STATUS} value={data.status} />
            </div>

            {actionError && <div role="alert" className="rounded-lg border border-error/20 bg-error-bg px-3.5 py-2.5 text-sm text-error">{actionError}</div>}

            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" onClick={() => setEditing(true)}>
                <Pencil className="h-4 w-4" />
                Edit
              </Button>
              {next.map((status) => (
                <Button key={status} variant={status === 'archived' ? 'secondary' : 'primary'} onClick={() => setPending(status)}>
                  {ACTION_LABEL[status]}
                </Button>
              ))}
            </div>

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Location">{data.location}</DetailItem>
              <DetailItem label="Work mode">{data.work_mode ? WORK_MODE_LABEL[data.work_mode] : null}</DetailItem>
              {kind === 'job' ? (
                <>
                  <DetailItem label="Salary">{data.salary_range}</DetailItem>
                  <DetailItem label="Employment">{data.employment_type ? EMPLOYMENT_TYPE_LABEL[data.employment_type] : null}</DetailItem>
                </>
              ) : (
                <>
                  <DetailItem label="Stipend">{data.stipend}</DetailItem>
                  <DetailItem label="Duration">{data.duration_months ? `${data.duration_months} months` : null}</DetailItem>
                </>
              )}
              <DetailItem label="Deadline">{formatDate(data.application_deadline)}</DetailItem>
              <DetailItem label="Openings">{data.openings ? String(data.openings) : null}</DetailItem>
              <DetailItem label="Accepting applications">{data.is_active ? 'Yes' : 'No'}</DetailItem>
              <DetailItem label="Created">{`${formatDateTime(data.created_at)}${data.creator ? ` · ${data.creator.name}` : ''}`}</DetailItem>
            </dl>

            <section>
              <h4 className="mb-1 text-xs font-medium uppercase tracking-wide text-ink-faint">Description</h4>
              {data.description ? <p className="whitespace-pre-line text-sm leading-relaxed text-ink">{data.description}</p> : <p className="text-sm text-ink-faint">No description</p>}
            </section>

            <section className="rounded-xl border border-hairline p-4">
              <h4 className="mb-3 text-sm font-semibold text-ink">Eligibility {e?.enforced ? <span className="ml-1 text-xs font-medium text-success">enforced</span> : <span className="ml-1 text-xs font-medium text-ink-faint">not enforced</span>}</h4>
              <dl className="grid grid-cols-2 gap-4">
                <DetailItem label="Minimum CGPA">{e?.minimum_cgpa != null ? String(e.minimum_cgpa) : null}</DetailItem>
                <DetailItem label="Maximum backlogs">{e?.maximum_backlogs != null ? String(e.maximum_backlogs) : null}</DetailItem>
                <DetailItem label="Years">{e?.years?.length ? e.years.map((y) => `Year ${y}`).join(', ') : null}</DetailItem>
                <DetailItem label="Departments">{e?.departments?.length ? e.departments.map((d) => d.name).join(', ') : null}</DetailItem>
                <DetailItem label="Batches">{e?.batches?.length ? e.batches.map((b) => b.name).join(', ') : null}</DetailItem>
                <DetailItem label="Required skills">{e?.required_skills?.length ? e.required_skills.join(', ') : null}</DetailItem>
              </dl>
            </section>
          </div>
        ) : null}
      </Drawer>

      {editing && data && (
        <OpportunityFormModal
          kind={kind}
          existing={data}
          onClose={() => setEditing(false)}
          onSaved={() => {
            reload()
            onChanged()
          }}
        />
      )}

      <ConfirmDialog
        open={pending !== null}
        onClose={() => setPending(null)}
        onConfirm={transition}
        isConfirming={busy}
        title={pending ? `${ACTION_LABEL[pending]}?` : ''}
        description={pending ? ACTION_HELP[pending] : undefined}
        confirmLabel={pending ? ACTION_LABEL[pending] : 'Confirm'}
        tone={pending === 'archived' ? 'danger' : 'default'}
      />
    </>
  )
}
