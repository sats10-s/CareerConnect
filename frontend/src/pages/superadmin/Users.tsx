import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Plus, Users as UsersIcon } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Avatar } from '@/components/ui/Avatar'
import { Button } from '@/components/ui/Button'
import { DataTable } from '@/components/ui/DataTable'
import { EmptyState } from '@/components/ui/EmptyState'
import { Pagination } from '@/components/ui/Pagination'
import { SearchBar } from '@/components/ui/SearchBar'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { EmptySearchIllustration } from '@/components/illustrations/EmptyStateIllustrations'
import { FilterBar, FilterSelect } from '@/components/common/FilterBar'
import { ListState } from '@/components/common/ListState'
import { UserDrawer } from '@/components/superadmin/UserDrawer'
import { UserFormModal } from '@/components/superadmin/UserFormModal'
import { useApiList } from '@/hooks/useApiList'
import { superAdminService } from '@/services/superAdminService'
import { ROLE_LABEL } from '@/utils/roles'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { Role } from '@/types/auth'
import type { ManagedUser } from '@/types/superAdmin'

const ROLE_OPTIONS = (Object.keys(ROLE_LABEL) as Role[]).map((r) => ({ value: r, label: ROLE_LABEL[r] }))
const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
]

function UserCell({ row }: { row: ManagedUser }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Avatar name={row.name} size="sm" />
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">
          {row.name}
          {row.is_self && <span className="ml-1.5 text-xs font-normal text-ink-faint">(you)</span>}
        </p>
        <p className="truncate text-xs text-ink-faint">{row.email}</p>
      </div>
    </div>
  )
}

const statusBadge = (row: ManagedUser) => <StatusBadge tone={row.is_active ? 'success' : 'error'}>{row.is_active ? 'Active' : 'Inactive'}</StatusBadge>
const lastSeen = (row: ManagedUser) => (row.last_login_at ? formatRelativeTime(row.last_login_at) : 'Never')
const affiliation = (row: ManagedUser) =>
  row.student ? [row.student.roll_number, row.student.department].filter(Boolean).join(' · ') : row.faculty ? [row.faculty.employee_code, row.faculty.department].filter(Boolean).join(' · ') : '—'

/**
 * Super Admin > Users - every account (GET /super-admin/users). Staff
 * accounts are created here; students self-register. Opening a row gives
 * edit, activate/deactivate, reset password and sign out everywhere.
 */
export function SuperAdminUsersPage() {
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState('')
  const role = params.get('role') ?? ''
  const status = params.get('status') ?? ''
  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)

  const setFilter = (key: 'role' | 'status', value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  const list = useApiList(superAdminService.users.list, {
    search: search.trim() || undefined,
    role: (role || undefined) as Role | undefined,
    status: (status || undefined) as 'active' | 'inactive' | undefined,
  })
  const hasFilters = Boolean(search.trim() || role || status)
  const clearFilters = () => {
    setSearch('')
    setParams(new URLSearchParams(), { replace: true })
  }

  const columns = [
    { key: 'user', header: 'User', render: (row: ManagedUser) => <UserCell row={row} /> },
    { key: 'role', header: 'Role', render: (row: ManagedUser) => <span className="text-sm text-ink-soft">{row.role_label}</span> },
    { key: 'affiliation', header: 'Roll / employee code', render: (row: ManagedUser) => <span className="text-sm text-ink-soft">{affiliation(row)}</span> },
    { key: 'last', header: 'Last sign-in', render: (row: ManagedUser) => <span className="text-sm text-ink-soft">{lastSeen(row)}</span> },
    { key: 'status', header: 'Status', render: statusBadge },
  ]

  return (
    <div className="animate-fade-in">
      <PageHeader title="Users" description="Every account on the platform. Add staff, change admin roles, and control who can sign in." />

      <FilterBar>
        <SearchBar value={search} onChange={setSearch} placeholder="Search by name or email" className="w-full sm:w-72" />
        <FilterSelect label="Role" value={role} onChange={(v) => setFilter('role', v)} options={ROLE_OPTIONS} allLabel="All roles" />
        <FilterSelect label="Status" value={status} onChange={(v) => setFilter('status', v)} options={STATUS_OPTIONS} allLabel="Any status" />
        {hasFilters && <Button variant="secondary" onClick={clearFilters}>Clear filters</Button>}
        <div className="w-full sm:ml-auto sm:w-auto">
          <Button className="w-full sm:w-auto" onClick={() => setCreating(true)}>
            <Plus className="h-4 w-4" />
            Add staff account
          </Button>
        </div>
      </FilterBar>

      {list.meta && (
        <p className="mb-3 text-xs text-ink-faint" aria-live="polite">
          {list.meta.total} account{list.meta.total === 1 ? '' : 's'}{hasFilters ? ' match' : ''}
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
            <EmptyState illustration={<EmptySearchIllustration />} title="No accounts match" description="Try a different name, role or status." action={<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>} />
          ) : (
            <EmptyState icon={UsersIcon} title="No accounts yet" description="Add the first staff account to get started." action={<Button onClick={() => setCreating(true)}>Add staff account</Button>} />
          )
        }
      >
        <DataTable
          columns={columns}
          rows={list.items}
          rowKey={(row) => row.id}
          onRowClick={(row) => setOpenId(row.id)}
          renderMobileCard={(row) => (
            <div className="space-y-2">
              <div className="flex items-start justify-between gap-3">
                <UserCell row={row} />
                {statusBadge(row)}
              </div>
              <p className="text-xs text-ink-faint">{row.role_label} · Last sign-in: {lastSeen(row)}</p>
            </div>
          )}
        />
        <div className="mt-4">
          <Pagination page={list.page} totalPages={list.meta?.last_page ?? 1} onChange={list.setPage} />
        </div>
      </ListState>

      <UserDrawer id={openId} onClose={() => setOpenId(null)} onChanged={list.reload} />
      {creating && <UserFormModal onClose={() => setCreating(false)} onSaved={(u) => { list.reload(); setOpenId(u.id) }} />}
    </div>
  )
}
