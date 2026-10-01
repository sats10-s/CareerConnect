import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { History } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { Drawer } from '@/components/ui/Drawer'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { DetailItem } from '@/components/common/Cells'
import { FilterBar, FilterDate, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { useApiList } from '@/hooks/useApiList'
import { useApiResource } from '@/hooks/useApiResource'
import { superAdminService } from '@/services/superAdminService'
import { AUDIT_AREAS, toneForAuditAction } from '@/utils/auditMeta'
import { formatDateTime } from '@/utils/dateFormat'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import { ROLE_LABEL } from '@/utils/roles'
import type { AuditLogEntry, AuditLogParams } from '@/types/superAdmin'

/** Failed/blocked sign-ins are recorded against the account that was tried, not a verified person. */
const isUnverified = (action: string) => action === 'auth.login_failed' || action === 'auth.login_blocked'

function actorText(row: AuditLogEntry): string {
  if (isUnverified(row.action)) return row.actor ? `Unverified attempt as ${row.actor.name}` : 'Unverified attempt'
  if (!row.actor) return 'System / signed-out visitor'
  return `${row.actor.name}${row.actor.role ? ` (${ROLE_LABEL[row.actor.role]})` : ''}`
}

function ActorCell({ row }: { row: AuditLogEntry }) {
  if (isUnverified(row.action)) {
    return (
      <div className="min-w-0">
        <p className="truncate text-sm text-ink-soft">Unverified</p>
        <p className="truncate text-xs text-ink-faint">{row.actor ? `tried as ${row.actor.name}` : 'unknown account'}</p>
      </div>
    )
  }
  if (!row.actor) return <span className="text-sm text-ink-faint">System / signed-out</span>
  return (
    <div className="min-w-0">
      <p className="truncate text-sm font-medium text-ink">{row.actor.name}</p>
      <p className="truncate text-xs text-ink-faint">{row.actor.role ? ROLE_LABEL[row.actor.role] : '—'}</p>
    </div>
  )
}

function When({ iso }: { iso: string | null }) {
  if (!iso) return <span className="text-ink-faint">—</span>
  return <time dateTime={iso} title={formatDateTime(iso)} className="whitespace-nowrap text-sm text-ink-soft">{formatRelativeTime(iso)}</time>
}

const isPrimitiveList = (value: unknown): value is (string | number | boolean)[] => Array.isArray(value) && value.every((v) => v === null || typeof v !== 'object')

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (isPrimitiveList(value)) return value.length ? value.map((v) => (typeof v === 'string' ? humanize(v) : String(v))).join(', ') : '—'
  if (typeof value === 'object') return JSON.stringify(value, null, 2)
  return String(value)
}

const humanize = (key: string) => key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

/** Renders an entry's metadata; "changes" maps ({field: {from, to}}) get a before → after table. */
function Metadata({ metadata }: { metadata: Record<string, unknown> }) {
  const { changes, ...rest } = metadata as { changes?: Record<string, { from: unknown; to: unknown } | unknown> } & Record<string, unknown>
  const changeRows = changes && typeof changes === 'object' && !Array.isArray(changes) ? Object.entries(changes) : []
  const restRows = Object.entries(rest)
  return (
    <div className="space-y-4">
      {changeRows.length > 0 && (
        <div className="card overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-hairline text-xs uppercase tracking-wide text-ink-faint">
                <th className="px-3 py-2 font-semibold">Field</th>
                <th className="px-3 py-2 font-semibold">Before</th>
                <th className="px-3 py-2 font-semibold">After</th>
              </tr>
            </thead>
            <tbody>
              {changeRows.map(([field, change]) => {
                const pair = change && typeof change === 'object' && 'to' in (change as object) ? (change as { from: unknown; to: unknown }) : { from: undefined, to: change }
                return (
                  <tr key={field} className="border-b border-hairline last:border-0">
                    <td className="px-3 py-2 text-ink-soft">{humanize(field)}</td>
                    <td className="px-3 py-2 text-ink-faint line-through decoration-ink-faint/50">{formatValue(pair.from)}</td>
                    <td className="px-3 py-2 text-ink">{formatValue(pair.to)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
      {restRows.length > 0 && (
        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {restRows.map(([key, value]) => (
            <DetailItem key={key} label={humanize(key)}>
              {typeof value === 'object' && value !== null && !isPrimitiveList(value) ? <pre className="whitespace-pre-wrap font-mono text-xs text-ink-soft">{formatValue(value)}</pre> : formatValue(value)}
            </DetailItem>
          ))}
        </dl>
      )}
    </div>
  )
}

function AuditEntryDrawer({ entry, onClose }: { entry: AuditLogEntry | null; onClose: () => void }) {
  return (
    <Drawer open={entry !== null} onClose={onClose} title={entry?.action_label ?? 'Event'} width="md">
      {entry && (
        <div className="space-y-6">
          <div>
            <StatusBadge tone={toneForAuditAction(entry.action)}>{entry.action_label}</StatusBadge>
            <p className="mt-3 text-sm leading-relaxed text-ink">{entry.description}</p>
          </div>
          <dl className="grid grid-cols-2 gap-4">
            <DetailItem label="When">{formatDateTime(entry.created_at)}</DetailItem>
            <DetailItem label="By">{actorText(entry)}</DetailItem>
            <DetailItem label="IP address">{entry.ip_address}</DetailItem>
            <DetailItem label="Event key"><code className="font-mono text-xs">{entry.action}</code></DetailItem>
            {entry.subject_type && <DetailItem label="Record">{`${entry.subject_type.split('\\').pop()} #${entry.subject_id ?? '—'}`}</DetailItem>}
          </dl>
          {entry.user_agent && (
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">Device</p>
              <p className="mt-1 break-words font-mono text-xs text-ink-soft">{entry.user_agent}</p>
            </div>
          )}
          {entry.metadata && Object.keys(entry.metadata).length > 0 && (
            <section>
              <h4 className="mb-3 text-sm font-semibold text-ink">Details</h4>
              <Metadata metadata={entry.metadata} />
            </section>
          )}
        </div>
      )}
    </Drawer>
  )
}

/**
 * Super Admin > Audit Logs (GET /super-admin/audit-logs): sign-ins, failed
 * and blocked sign-ins, account changes, departments, batches, companies
 * and settings - who did it, when and from where. Read-only.
 */
export function SuperAdminAuditLogsPage() {
  const [params] = useSearchParams()
  const [search, setSearch] = useState('')
  const [area, setArea] = useState(() => {
    const fromUrl = params.get('area') ?? ''
    return AUDIT_AREAS.some((a) => a.value === fromUrl) ? fromUrl : ''
  })
  const [action, setAction] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [open, setOpen] = useState<AuditLogEntry | null>(null)
  const actions = useApiResource(() => superAdminService.auditLogs({ per_page: 1 }).then((d) => d.actions), 'audit-actions')

  // Don't send an inverted range (the API would reject it) - flag it under the filters instead.
  const rangeInvalid = Boolean(from && to && to < from)
  const list = useApiList(
    superAdminService.auditLogs,
    {
      search: search.trim() || undefined,
      area: (area || undefined) as AuditLogParams['area'],
      action: action || undefined,
      date_from: from || undefined,
      date_to: (!rangeInvalid && to) || undefined,
    },
    25,
  )
  const hasFilters = Boolean(search.trim() || area || action || from || to)
  const clearFilters = () => {
    setSearch('')
    setArea('')
    setAction('')
    setFrom('')
    setTo('')
  }
  const actionOptions = (actions.data ?? []).filter((a) => !area || a.value.startsWith(`${area}.`))

  const columns = [
    { key: 'when', header: 'When', render: (row: AuditLogEntry) => <When iso={row.created_at} /> },
    { key: 'event', header: 'Event', render: (row: AuditLogEntry) => <StatusBadge tone={toneForAuditAction(row.action)}>{row.action_label}</StatusBadge> },
    { key: 'description', header: 'Description', className: 'max-w-md', render: (row: AuditLogEntry) => <span className="line-clamp-2 text-sm text-ink">{row.description}</span> },
    { key: 'actor', header: 'By', render: (row: AuditLogEntry) => <ActorCell row={row} /> },
    { key: 'ip', header: 'IP', render: (row: AuditLogEntry) => <span className="font-mono text-xs text-ink-faint">{row.ip_address ?? '—'}</span> },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Audit logs" description="Every sign-in and administrative change, newest first." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search events, people or IP" className="w-full sm:w-72" />
        <FilterSelect label="Area" value={area} onChange={(v) => { setArea(v); setAction('') }} options={AUDIT_AREAS.map((a) => ({ value: a.value, label: a.label }))} allLabel="All areas" />
        <FilterSelect label="Event" value={action} onChange={setAction} options={actionOptions} allLabel="All events" />
        <FilterDate label="From" value={from} onChange={setFrom} />
        <FilterDate label="To" value={to} onChange={setTo} />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
      </FilterBar>
      {rangeInvalid && <p role="alert" className="-mt-2 mb-4 text-xs text-error">The end date is before the start date.</p>}

      {list.meta && (
        <p className="mb-3 text-xs text-ink-faint" aria-live="polite">
          {list.meta.total.toLocaleString()} event{list.meta.total === 1 ? '' : 's'}
        </p>
      )}

      <ListState
        isLoading={list.isLoading}
        error={list.error}
        isEmpty={list.items.length === 0}
        hasLoadedOnce={list.meta !== null}
        onRetry={list.reload}
        empty={
          hasFilters ? (
            <EmptyState illustration={<EmptySearchIllustration />} title="No events match" description="Try a wider date range or a different area." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState icon={History} title="Nothing recorded yet" description="Sign-ins and administrative changes appear here as they happen." />
          )
        }
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={setOpen}
          renderMobileCard={(row) => (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <StatusBadge tone={toneForAuditAction(row.action)}>{row.action_label}</StatusBadge>
                <When iso={row.created_at} />
              </div>
              <p className="text-sm text-ink">{row.description}</p>
              <p className="text-xs text-ink-faint">{actorText(row)}{row.ip_address ? ` · ${row.ip_address}` : ''}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <AuditEntryDrawer entry={open} onClose={() => setOpen(null)} />
    </div>
  )
}
