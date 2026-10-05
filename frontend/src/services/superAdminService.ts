import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type {
  AuditLogEntry,
  AuditLogParams,
  BatchRecord,
  CompanyApprovalRecord,
  CompanyStatusCounts,
  CreateUserPayload,
  DepartmentRecord,
  InstitutionSettings,
  ManagedUser,
  PublicSettings,
  RoleSummary,
  SecurityOverview,
  UpdateUserPayload,
  UserListParams,
} from '@/types/superAdmin'

/** /api/super-admin/* (role:SUPER_ADMIN). Every write is audited on the server. */
const unwrap = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data)

export const superAdminService = {
  users: {
    list: (params: UserListParams = {}) => unwrap(api.get<ApiEnvelope<PaginatedList<ManagedUser>>>('/super-admin/users', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<ManagedUser>>(`/super-admin/users/${id}`)),
    create: (payload: CreateUserPayload) => unwrap(api.post<ApiEnvelope<ManagedUser>>('/super-admin/users', payload)),
    update: (id: number, payload: UpdateUserPayload) => unwrap(api.patch<ApiEnvelope<ManagedUser>>(`/super-admin/users/${id}`, payload)),
    setActive: (id: number, is_active: boolean) => unwrap(api.patch<ApiEnvelope<ManagedUser>>(`/super-admin/users/${id}/status`, { is_active })),
    resetPassword: (id: number, password: string, password_confirmation: string) =>
      api.post<ApiEnvelope<null>>(`/super-admin/users/${id}/password`, { password, password_confirmation }).then((r) => r.data.message),
    revokeSessions: (id: number) => unwrap(api.delete<ApiEnvelope<{ revoked: number }>>(`/super-admin/users/${id}/sessions`)),
  },
  roles: () => unwrap(api.get<ApiEnvelope<{ items: RoleSummary[] }>>('/super-admin/roles')).then((d) => d.items),
  departments: {
    list: () => unwrap(api.get<ApiEnvelope<{ items: DepartmentRecord[] }>>('/super-admin/departments')).then((d) => d.items),
    create: (payload: { name: string; code: string }) => unwrap(api.post<ApiEnvelope<DepartmentRecord>>('/super-admin/departments', payload)),
    update: (id: number, payload: { name?: string; code?: string }) => unwrap(api.patch<ApiEnvelope<DepartmentRecord>>(`/super-admin/departments/${id}`, payload)),
    remove: (id: number) => api.delete(`/super-admin/departments/${id}`),
  },
  batches: {
    list: () => unwrap(api.get<ApiEnvelope<{ items: BatchRecord[] }>>('/super-admin/batches')).then((d) => d.items),
    create: (payload: { start_year: number; end_year: number; name?: string }) => unwrap(api.post<ApiEnvelope<BatchRecord>>('/super-admin/batches', payload)),
    update: (id: number, payload: { start_year?: number; end_year?: number; name?: string }) => unwrap(api.patch<ApiEnvelope<BatchRecord>>(`/super-admin/batches/${id}`, payload)),
    remove: (id: number) => api.delete(`/super-admin/batches/${id}`),
  },
  auditLogs: (params: AuditLogParams = {}) =>
    unwrap(api.get<ApiEnvelope<PaginatedList<AuditLogEntry> & { actions: { value: string; label: string }[] }>>('/super-admin/audit-logs', { params })),
  security: () => unwrap(api.get<ApiEnvelope<SecurityOverview>>('/super-admin/security')),
  settings: {
    get: () => unwrap(api.get<ApiEnvelope<{ values: InstitutionSettings }>>('/super-admin/settings')).then((d) => d.values),
    update: (payload: Partial<InstitutionSettings>) =>
      api.patch<ApiEnvelope<{ values: InstitutionSettings }>>('/super-admin/settings', payload).then((r) => ({ values: r.data.data.values, message: r.data.message })),
  },
  companies: {
    list: (params: { status?: string; search?: string; page?: number; per_page?: number } = {}) =>
      unwrap(api.get<ApiEnvelope<PaginatedList<CompanyApprovalRecord> & { counts: CompanyStatusCounts }>>('/super-admin/companies', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<CompanyApprovalRecord>>(`/super-admin/companies/${id}`)),
    approve: (id: number, note?: string) => unwrap(api.post<ApiEnvelope<CompanyApprovalRecord>>(`/super-admin/companies/${id}/approve`, { note })),
    reject: (id: number, reason: string) => unwrap(api.post<ApiEnvelope<CompanyApprovalRecord>>(`/super-admin/companies/${id}/reject`, { reason })),
    suspend: (id: number, reason: string) => unwrap(api.post<ApiEnvelope<CompanyApprovalRecord>>(`/super-admin/companies/${id}/suspend`, { reason })),
    reactivate: (id: number, note?: string) => unwrap(api.post<ApiEnvelope<CompanyApprovalRecord>>(`/super-admin/companies/${id}/reactivate`, { note })),
  },
  publicSettings: () => api.get<PublicSettings>('/meta/settings').then((r) => r.data),
}
