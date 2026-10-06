import { useState } from 'react'
import { AlertTriangle, Ban, CheckCircle2, ExternalLink, RotateCcw, XCircle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { DetailItem, MetaBadge } from '@/components/common/Cells'
import { useApiResource } from '@/hooks/useApiResource'
import { superAdminService } from '@/services/superAdminService'
import { parseApiError } from '@/utils/apiError'
import { formatDate, formatDateTime } from '@/utils/dateFormat'
import { COMPANY_STATUS } from '@/utils/statusMeta'
import type { CompanyApprovalRecord } from '@/types/superAdmin'

type Decision = 'approve' | 'reject' | 'suspend' | 'reactivate'

const DECISION: Record<Decision, { title: string; confirm: string; needsReason: boolean; danger: boolean; describe: (name: string) => string; done: string }> = {
  approve: {
    title: 'Approve company',
    confirm: 'Approve',
    needsReason: false,
    danger: false,
    describe: (name) => `${name} will be able to use the company portal, and its published postings become visible to students.`,
    done: 'Company approved.',
  },
  reject: {
    title: 'Reject registration',
    confirm: 'Reject',
    needsReason: true,
    danger: true,
    describe: (name) => `${name} stays locked out of the portal. The owner sees the reason you give.`,
    done: 'Company rejected.',
  },
  suspend: {
    title: 'Suspend company',
    confirm: 'Suspend',
    needsReason: true,
    danger: true,
    describe: (name) => `Every recruiter at ${name} is signed out immediately and its postings are hidden from students until you reactivate it.`,
    done: 'Company suspended and its recruiters signed out.',
  },
  reactivate: {
    title: 'Reactivate company',
    confirm: 'Reactivate',
    needsReason: false,
    danger: false,
    describe: (name) => `${name} gets portal access back and its published postings are visible to students again.`,
    done: 'Company reactivated.',
  },
}

const ACTIONS_FOR_STATUS: Record<string, Decision[]> = {
  PENDING: ['reject', 'approve'],
  REJECTED: ['approve'],
  APPROVED: ['suspend'],
  SUSPENDED: ['reactivate'],
}

function DecisionButton({ decision, onClick }: { decision: Decision; onClick: () => void }) {
  const icon = { approve: CheckCircle2, reject: XCircle, suspend: Ban, reactivate: RotateCcw }[decision]
  const Icon = icon
  const variant = decision === 'reject' || decision === 'suspend' ? 'danger' : 'primary'
  const label = decision === 'approve' ? 'Approve' : DECISION[decision].confirm
  return (
    <Button variant={variant} onClick={onClick}>
      <Icon className="h-4 w-4" />
      {label}
    </Button>
  )
}

function DuplicateWarning({ company }: { company: CompanyApprovalRecord }) {
  if (company.possible_duplicates.length === 0) return null
  return (
    <div role="note" className="rounded-xl border border-hairline bg-warning-bg p-4">
      <p className="flex items-center gap-2 text-sm font-semibold text-warning">
        <AlertTriangle className="h-4 w-4" aria-hidden="true" />
        Possible duplicate
      </p>
      <ul className="mt-2 space-y-1 text-sm text-ink-soft">
        {company.possible_duplicates.map((d) => (
          <li key={d.id}>
            <span className="font-medium text-ink">{d.name}</span> — same {d.reasons.join(' and ')}
            {d.status ? ` (${COMPANY_STATUS[d.status]?.label.toLowerCase() ?? d.status})` : ''}
          </li>
        ))}
      </ul>
    </div>
  )
}

/** GET /super-admin/companies/{id} with the approve / reject / suspend / reactivate decisions. */
export function CompanyReviewDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.companies.show(id as number), id)
  const [decision, setDecision] = useState<Decision | null>(null)
  const [text, setText] = useState('')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const openDecision = (next: Decision) => {
    setDecision(next)
    setText('')
    setFieldError(null)
  }

  async function confirm() {
    if (!data || !decision) return
    const spec = DECISION[decision]
    const value = text.trim()
    if (spec.needsReason && value.length < 5) {
      setFieldError('Give a reason of at least 5 characters - the company will see it.')
      return
    }
    setBusy(true)
    try {
      const api = superAdminService.companies
      if (decision === 'approve') await api.approve(data.id, value || undefined)
      if (decision === 'reactivate') await api.reactivate(data.id, value || undefined)
      if (decision === 'reject') await api.reject(data.id, value)
      if (decision === 'suspend') await api.suspend(data.id, value)
      toast('success', spec.done)
      setDecision(null)
      reload()
      onChanged()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save the decision.')
      if (parsed.fieldErrors.reason) {
        setFieldError(parsed.fieldErrors.reason)
      } else {
        toast('error', parsed.message)
        setDecision(null)
      }
    } finally {
      setBusy(false)
    }
  }

  const actions = data ? ACTIONS_FOR_STATUS[data.status] ?? [] : []
  const spec = decision ? DECISION[decision] : null

  return (
    <>
      <Drawer
        open={id !== null}
        onClose={onClose}
        title={data?.name ?? 'Company'}
        width="md"
        footer={data && actions.length > 0 ? (
          <div className="flex flex-wrap justify-end gap-3">
            {actions.map((a) => <DecisionButton key={a} decision={a} onClick={() => openDecision(a)} />)}
          </div>
        ) : undefined}
      >
        {isLoading && !data ? (
          <div className="space-y-4" aria-busy="true">
            <LoadingSkeleton className="h-12 w-2/3" />
            <LoadingSkeleton className="h-24 w-full" />
          </div>
        ) : error ? (
          <ErrorState description={error} onRetry={reload} />
        ) : data ? (
          <div className="space-y-6">
            <div className="flex items-center gap-3">
              <CompanyLogo name={data.name} logoPath={data.logo_path} />
              <div className="min-w-0">
                <p className="truncate text-base font-semibold text-ink">{data.name}</p>
                <p className="text-sm text-ink-soft">{data.industry ?? 'Industry not set'}</p>
              </div>
              <div className="ml-auto"><MetaBadge map={COMPANY_STATUS} value={data.status} /></div>
            </div>

            <DuplicateWarning company={data} />

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Website">
                {data.website ? (
                  <a href={data.website} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline">
                    {data.website.replace(/^https?:\/\//, '')}
                    <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                  </a>
                ) : null}
              </DetailItem>
              <DetailItem label="Source">{data.source === 'SELF_REGISTERED' ? 'Self-registered' : 'Added by placement office'}</DetailItem>
              <DetailItem label="Headquarters">{data.headquarters}</DetailItem>
              <DetailItem label="Size">{data.company_size ? `${data.company_size} employees` : null}</DetailItem>
              <DetailItem label="Registration no.">{data.registration_number}</DetailItem>
              <DetailItem label="Registered">{formatDate(data.created_at)}</DetailItem>
            </dl>

            {data.description && <p className="whitespace-pre-line text-sm leading-relaxed text-ink-soft">{data.description}</p>}

            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">Contact & owner login</h4>
              <dl className="card grid grid-cols-2 gap-4 p-4">
                <DetailItem label="Contact person">{data.contact_name}</DetailItem>
                <DetailItem label="Contact email">{data.contact_email}</DetailItem>
                <DetailItem label="Phone">{data.contact_phone}</DetailItem>
                <DetailItem label="Owner login">{data.owner ? data.owner.email : 'No company login'}</DetailItem>
                {data.owner && <DetailItem label="Last sign-in">{data.owner.last_login_at ? formatDateTime(data.owner.last_login_at) : 'Never'}</DetailItem>}
              </dl>
            </section>

            {data.reviewed_at && (
              <section>
                <h4 className="mb-3 text-sm font-semibold text-ink">Last decision</h4>
                <div className="card p-4 text-sm">
                  <p className="text-ink-soft">
                    {data.status_label} by <span className="font-medium text-ink">{data.reviewed_by?.name ?? 'a former admin'}</span> on {formatDateTime(data.reviewed_at)}
                  </p>
                  {data.review_note && <p className="mt-2 whitespace-pre-line text-ink">{data.review_note}</p>}
                </div>
              </section>
            )}
          </div>
        ) : null}
      </Drawer>

      {spec && data && (
        <ConfirmDialog
          open
          onClose={() => !busy && setDecision(null)}
          onConfirm={confirm}
          title={spec.title}
          description={spec.describe(data.name)}
          confirmLabel={spec.confirm}
          tone={spec.danger ? 'danger' : 'default'}
          isConfirming={busy}
        >
          <div className="mt-4">
            <label className="label" htmlFor="review-text">
              {spec.needsReason ? 'Reason' : <>Note <span className="font-normal text-ink-faint">(optional)</span></>}
            </label>
            <Textarea
              id="review-text"
              rows={3}
              maxLength={1000}
              value={text}
              onChange={(e) => { setText(e.target.value); setFieldError(null) }}
              error={fieldError ?? undefined}
              placeholder={spec.needsReason ? 'Shown to the company owner' : 'Saved with the decision'}
            />
          </div>
        </ConfirmDialog>
      )}
    </>
  )
}
