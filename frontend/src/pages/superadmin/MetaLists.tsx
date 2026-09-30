import type { ReactNode } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { EmptyBriefcaseIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { ListState } from '@/components/common/ListState'
import { useApiResource } from '@/hooks/useApiResource'
import { metaService } from '@/services/metaService'
import type { Batch, Department } from '@/types/meta'

const READ_ONLY_NOTE = 'Read-only: the server publishes this list for registration forms but has no add or edit endpoints yet.'

function ReadOnlyList({ children, note }: { children: ReactNode; note: string }) {
  return (
    <div>
      {children}
      <p className="mt-4 text-xs text-ink-faint">{note}</p>
    </div>
  )
}

/** Super Admin > Departments: GET /meta/departments (read-only). */
export function SuperAdminDepartmentsPage() {
  const { data, isLoading, error, reload } = useApiResource(() => metaService.departments(), 'departments')
  const rows = data ?? []
  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="Departments" description="The departments students and faculty belong to." />
      <ReadOnlyList note={READ_ONLY_NOTE}>
        <ListState isLoading={isLoading} error={error} isEmpty={rows.length === 0} hasLoadedOnce={data !== null} onRetry={reload} empty={<EmptyState illustration={<EmptyBriefcaseIllustration />} title="No departments yet" description="Departments appear here once they exist in the system." />}>
          <DataTable
            columns={[
              { key: 'name', header: 'Department', render: (r: Department) => <span className="text-sm font-medium text-ink">{r.name}</span> },
              { key: 'code', header: 'Code', render: (r: Department) => <span className="text-sm text-ink-soft">{r.code}</span> },
            ]}
            rows={rows}
            rowKey={(r) => r.id}
            renderMobileCard={(r) => <div className="flex items-center justify-between gap-3"><p className="text-sm font-medium text-ink">{r.name}</p><span className="text-xs text-ink-faint">{r.code}</span></div>}
          />
        </ListState>
      </ReadOnlyList>
    </div>
  )
}

/** Super Admin > Batches: GET /meta/batches (read-only). */
export function SuperAdminBatchesPage() {
  const { data, isLoading, error, reload } = useApiResource(() => metaService.batches(), 'batches')
  const rows = data ?? []
  return (
    <div className="animate-fade-in space-y-6">
      <PageHeader title="Batches" description="The graduating batches students are registered under." />
      <ReadOnlyList note={READ_ONLY_NOTE}>
        <ListState isLoading={isLoading} error={error} isEmpty={rows.length === 0} hasLoadedOnce={data !== null} onRetry={reload} empty={<EmptyState illustration={<EmptyBriefcaseIllustration />} title="No batches yet" description="Batches appear here once they exist in the system." />}>
          <DataTable
            columns={[
              { key: 'name', header: 'Batch', render: (r: Batch) => <span className="text-sm font-medium text-ink">{r.name}</span> },
              { key: 'years', header: 'Years', render: (r: Batch) => <span className="text-sm tabular-nums text-ink-soft">{r.start_year} - {r.end_year}</span> },
            ]}
            rows={rows}
            rowKey={(r) => r.id}
            renderMobileCard={(r) => <div className="flex items-center justify-between gap-3"><p className="text-sm font-medium text-ink">{r.name}</p><span className="text-xs tabular-nums text-ink-faint">{r.start_year} - {r.end_year}</span></div>}
          />
        </ListState>
      </ReadOnlyList>
    </div>
  )
}
