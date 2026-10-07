import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type {
  DepartmentAliasRecord,
  OneTimePassword,
  StudentImportBatch,
  StudentImportRow,
  StudentImportTab,
  StudentImportType,
} from '@/types/studentData'

/** /api/super-admin/student-data/* (role:SUPER_ADMIN). */
const unwrap = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data)
const base = '/super-admin/student-data'

export const studentDataService = {
  imports: {
    list: (params: { type?: StudentImportType; page?: number; per_page?: number } = {}) =>
      unwrap(api.get<ApiEnvelope<PaginatedList<StudentImportBatch>>>(`${base}/imports`, { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<StudentImportBatch>>(`${base}/imports/${id}`)),
    upload: (type: StudentImportType, file: File, semesterLabel: string | null, onProgress?: (percent: number) => void) => {
      const form = new FormData()
      form.append('type', type)
      form.append('file', file)
      if (semesterLabel) form.append('semester_label', semesterLabel)
      return unwrap(
        api.post<ApiEnvelope<StudentImportBatch>>(`${base}/imports`, form, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (event) => {
            if (onProgress && event.total) onProgress(Math.round((event.loaded / event.total) * 100))
          },
        }),
      )
    },
    rows: (id: number, params: { tab: StudentImportTab; page?: number; per_page?: number; search?: string }) =>
      unwrap(api.get<ApiEnvelope<PaginatedList<StudentImportRow>>>(`${base}/imports/${id}/rows`, { params })),
    recheck: (id: number) => unwrap(api.post<ApiEnvelope<StudentImportBatch>>(`${base}/imports/${id}/recheck`)),
    confirm: (id: number) => unwrap(api.post<ApiEnvelope<StudentImportBatch>>(`${base}/imports/${id}/confirm`)),
    cancel: (id: number) => unwrap(api.post<ApiEnvelope<StudentImportBatch>>(`${base}/imports/${id}/cancel`)),
    rollback: (id: number) =>
      unwrap(api.post<ApiEnvelope<{ result: { removed: number; reverted: number; conflicts: number }; batch: StudentImportBatch }>>(`${base}/imports/${id}/rollback`)),
    oneTimePasswords: (id: number) => unwrap(api.post<ApiEnvelope<{ items: OneTimePassword[] }>>(`${base}/imports/${id}/one-time-passwords`)).then((d) => d.items),
    issues: (id: number) => api.get(`${base}/imports/${id}/issues`, { responseType: 'blob' }).then((r) => r.data as Blob),
  },
  /** Name, Department, College Email, Password. */
  placementAdminTemplate: () => api.get(`${base}/templates/placement-admins`, { responseType: 'blob' }).then((r) => r.data as Blob),
  aliases: {
    list: () => unwrap(api.get<ApiEnvelope<DepartmentAliasRecord[]>>(`${base}/department-aliases`)),
    create: (alias: string, department_id: number) => unwrap(api.post<ApiEnvelope<DepartmentAliasRecord>>(`${base}/department-aliases`, { alias, department_id })),
    remove: (id: number) => api.delete(`${base}/department-aliases/${id}`),
  },
}
