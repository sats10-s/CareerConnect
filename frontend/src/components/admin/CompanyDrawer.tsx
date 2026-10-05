import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ExternalLink, Pencil } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Drawer } from '@/components/ui/Drawer'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { DetailItem, MetaBadge } from '@/components/common/Cells'
import { CompanyFormModal } from './CompanyFormModal'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { formatDate } from '@/utils/dateFormat'
import { COMPANY_STATUS, OPPORTUNITY_STATUS } from '@/utils/statusMeta'

/** GET /admin/companies/{id}: details and every job/internship posted for the company, with edit. */
export function CompanyDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged: () => void }) {
  const { data, isLoading, error, reload } = useApiResource(() => adminService.companies.show(id as number), id)
  const [editing, setEditing] = useState(false)
  const postings = data?.postings ?? []

  return (
    <>
      <Drawer
        open={id !== null}
        onClose={onClose}
        title={data?.name ?? 'Company'}
        width="md"
        footer={data ? (
          <Button variant="secondary" onClick={() => setEditing(true)}>
            <Pencil className="h-4 w-4" />
            Edit
          </Button>
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

            {data.status !== 'APPROVED' && (
              <p className="rounded-lg border border-hairline bg-surface-raised px-3.5 py-2.5 text-sm text-ink-soft">
                {data.status === 'PENDING'
                  ? 'This self-registered company is waiting for Super Admin approval. Students won’t see its postings until then.'
                  : `This company is ${data.status_label.toLowerCase()}. Students won’t see its postings.`}
                {data.review_note ? ` Reason: ${data.review_note}` : ''}
              </p>
            )}

            <dl className="grid grid-cols-2 gap-4">
              <DetailItem label="Website">
                {data.website ? (
                  <a href={data.website} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline">
                    {data.website.replace(/^https?:\/\//, '')}
                    <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                  </a>
                ) : null}
              </DetailItem>
              <DetailItem label="Added">{formatDate(data.created_at)}</DetailItem>
              <DetailItem label="Job postings">{String(data.job_postings_count ?? 0)}</DetailItem>
              <DetailItem label="Internships">{String(data.internships_count ?? 0)}</DetailItem>
            </dl>

            {data.description && <p className="whitespace-pre-line text-sm leading-relaxed text-ink-soft">{data.description}</p>}

            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">Postings</h4>
              {postings.length === 0 ? (
                <p className="text-sm text-ink-faint">No jobs or internships posted for this company yet.</p>
              ) : (
                <ul className="card divide-y divide-hairline">
                  {postings.map((p) => (
                    <li key={`${p.type}-${p.id}`}>
                      <Link to={`/admin/${p.type === 'job' ? 'jobs' : 'internships'}?open=${p.id}`} className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-surface-raised">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-ink">{p.title}</p>
                          <p className="text-xs text-ink-faint">{p.type === 'job' ? 'Job' : 'Internship'} · {formatDate(p.created_at)}</p>
                        </div>
                        <MetaBadge map={OPPORTUNITY_STATUS} value={p.status} />
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        ) : null}
      </Drawer>
      {editing && data && (
        <CompanyFormModal
          existing={data}
          onClose={() => setEditing(false)}
          onSaved={() => {
            reload()
            onChanged()
          }}
        />
      )}
    </>
  )
}
