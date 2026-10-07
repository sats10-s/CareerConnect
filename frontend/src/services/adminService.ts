import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { ApplicationStatus } from '@/types/application'
import type { InternshipAssignmentStatus } from '@/types/internshipAssignment'
import type { AssignmentDetail, AssignmentListItem } from '@/types/coordination'
import type {
  AdminApplicationDetail,
  AdminApplicationRow,
  AdminOpportunity,
  AdminStudentDetail,
  AdminStudentRow,
  CompanyPayload,
  CompanyRecord,
  AttendanceReportRow,
  BreakdownRow,
  DriveDetail,
  DrivePayload,
  DriveRow,
  DriveStatus,
  DriveType,
  ExcelBatch,
  ExcelConfirmResult,
  ExcelExportType,
  ExcelImportType,
  ExcelPreviewRow,
  ExcelRollbackResult,
  FacultyWorkloadRow,
  FunnelData,
  InternshipReport,
  InterviewDetail,
  InterviewMode,
  InterviewPayload,
  InterviewRow,
  InterviewStatus,
  OpportunityLifecycle,
  OpportunityPayload,
  PlacementSummary,
  ReportFilters,
  StudentDirectoryParams,
} from '@/types/admin'

/**
 * Every /api/admin/* endpoint the Placement Admin screens use
 * (routes/api.php: role:PLACEMENT_ADMIN,SUPER_ADMIN). Object-level
 * authorization lives in the backend policies; nothing here decides
 * who may see what.
 */

type List<T> = Promise<PaginatedList<T>>
const unwrap = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data)

interface Paging {
  page?: number
  per_page?: number
  search?: string
}

export interface OpportunityListParams extends Paging {
  status?: OpportunityLifecycle
  company_id?: number
  source?: 'ADMIN' | 'COMPANY'
  /** Company drafts submitted for the placement office to review and publish. */
  awaiting_review?: 1
}

export interface AdminApplicationParams extends Paging {
  status?: ApplicationStatus
  type?: 'job' | 'internship'
  company_id?: number
  student_id?: number
}

export interface AdminInterviewParams {
  page?: number
  per_page?: number
  status?: InterviewStatus
  mode?: InterviewMode
  round?: number
  type?: 'job' | 'internship'
  company_id?: number
  student_id?: number
  application_status?: ApplicationStatus
  date_from?: string
  date_to?: string
}

export interface AdminDriveParams extends Paging {
  status?: DriveStatus
  drive_type?: DriveType
  company_id?: number
  when?: 'upcoming' | 'past'
  date_from?: string
  date_to?: string
}

export interface AdminAssignmentParams extends Paging {
  status?: InternshipAssignmentStatus
  faculty_id?: number
  student_id?: number
  internship_id?: number
  company_id?: number
  date_from?: string
  date_to?: string
}

export interface CreateAssignmentPayload {
  application_id: number
  faculty_id: number
  assigned_at?: string | null
  notes?: string | null
}

type OpportunityKind = 'jobs' | 'internships'

function opportunityApi(kind: OpportunityKind) {
  return {
    list: (params: OpportunityListParams = {}): List<AdminOpportunity> =>
      unwrap(api.get<ApiEnvelope<PaginatedList<AdminOpportunity>>>(`/admin/${kind}`, { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<AdminOpportunity>>(`/admin/${kind}/${id}`)),
    create: (payload: OpportunityPayload) => unwrap(api.post<ApiEnvelope<AdminOpportunity>>(`/admin/${kind}`, payload)),
    update: (id: number, payload: Partial<OpportunityPayload>) =>
      unwrap(api.patch<ApiEnvelope<AdminOpportunity>>(`/admin/${kind}/${id}`, payload)),
  }
}

export type ExportFilters = {
  department_id?: number
  batch_id?: number
  year?: number
  section?: string
  student_status?: string
  cgpa_min?: number
  max_backlogs?: number
  backlogs?: 'some' | 'many' | 'not_reported'
  job_id?: number
  internship_id?: number
  drive_id?: number
  company_id?: number
  faculty_id?: number
  status?: string
  applicable_type?: 'job' | 'internship'
  date_from?: string
  date_to?: string
}

export const adminService = {
  jobs: opportunityApi('jobs'),
  internships: opportunityApi('internships'),

  applications: {
    list: (params: AdminApplicationParams = {}): List<AdminApplicationRow> =>
      unwrap(api.get<ApiEnvelope<PaginatedList<AdminApplicationRow>>>('/admin/applications', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<AdminApplicationDetail>>(`/admin/applications/${id}`)),
    updateStatus: (id: number, status: ApplicationStatus, remarks?: string, offerPackage?: number | null) =>
      unwrap(api.patch<ApiEnvelope<AdminApplicationDetail>>(`/admin/applications/${id}/status`, { status, remarks, offer_package: offerPackage ?? undefined })),
    updateOffer: (id: number, offerPackage: number | null) =>
      unwrap(api.patch<ApiEnvelope<AdminApplicationDetail>>(`/admin/applications/${id}/offer`, { offer_package: offerPackage })),
    scheduleInterview: (id: number, payload: InterviewPayload) =>
      unwrap(api.post<ApiEnvelope<InterviewDetail>>(`/admin/applications/${id}/interviews`, payload)),
  },

  interviews: {
    list: (params: AdminInterviewParams = {}): List<InterviewRow> =>
      unwrap(api.get<ApiEnvelope<PaginatedList<InterviewRow>>>('/admin/interviews', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<InterviewDetail>>(`/admin/interviews/${id}`)),
    update: (id: number, payload: Partial<Omit<InterviewPayload, 'scheduled_at'>>) =>
      unwrap(api.patch<ApiEnvelope<InterviewDetail>>(`/admin/interviews/${id}`, payload)),
    reschedule: (id: number, payload: { scheduled_at: string; duration_minutes?: number | null; mode?: InterviewMode; meeting_url?: string | null; venue?: string | null; remarks?: string | null }) =>
      unwrap(api.post<ApiEnvelope<InterviewDetail>>(`/admin/interviews/${id}/reschedule`, payload)),
    complete: (id: number, remarks?: string) => unwrap(api.post<ApiEnvelope<InterviewDetail>>(`/admin/interviews/${id}/complete`, { remarks })),
    cancel: (id: number, reason: string) => unwrap(api.post<ApiEnvelope<InterviewDetail>>(`/admin/interviews/${id}/cancel`, { reason })),
  },

  drives: {
    list: (params: AdminDriveParams = {}): List<DriveRow> =>
      unwrap(api.get<ApiEnvelope<PaginatedList<DriveRow>>>('/admin/placement-drives', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<DriveDetail>>(`/admin/placement-drives/${id}`)),
    create: (payload: DrivePayload) => unwrap(api.post<ApiEnvelope<DriveDetail>>('/admin/placement-drives', payload)),
    update: (id: number, payload: Partial<DrivePayload>) => unwrap(api.patch<ApiEnvelope<DriveDetail>>(`/admin/placement-drives/${id}`, payload)),
    publish: (id: number) => unwrap(api.post<ApiEnvelope<DriveDetail>>(`/admin/placement-drives/${id}/publish`)),
    close: (id: number) => unwrap(api.post<ApiEnvelope<DriveDetail>>(`/admin/placement-drives/${id}/close`)),
    cancel: (id: number, reason: string) => unwrap(api.post<ApiEnvelope<DriveDetail>>(`/admin/placement-drives/${id}/cancel`, { reason })),
    /** Returns the new attachment row (not the drive) - callers reload the drive afterwards. */
    attach: (id: number, opportunity_type: 'job' | 'internship', opportunity_id: number) =>
      api.post<ApiEnvelope<unknown>>(`/admin/placement-drives/${id}/opportunities`, { opportunity_type, opportunity_id }).then(() => undefined),
    /** The route's {opportunity} segment is the ATTACHMENT id (PlacementDriveOpportunity), and the response carries no data. */
    detach: (id: number, attachmentId: number) =>
      api.delete<ApiEnvelope<null>>(`/admin/placement-drives/${id}/opportunities/${attachmentId}`).then(() => undefined),
  },

  assignments: {
    list: (params: AdminAssignmentParams = {}): List<AssignmentListItem> =>
      unwrap(api.get<ApiEnvelope<PaginatedList<AssignmentListItem>>>('/admin/internship-assignments', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<AssignmentDetail>>(`/admin/internship-assignments/${id}`)),
    create: (payload: CreateAssignmentPayload) => unwrap(api.post<ApiEnvelope<AssignmentDetail>>('/admin/internship-assignments', payload)),
    reassign: (id: number, faculty_id: number, remarks?: string) =>
      unwrap(api.patch<ApiEnvelope<AssignmentDetail>>(`/admin/internship-assignments/${id}/faculty`, { faculty_id, remarks })),
    activate: (id: number, started_at?: string) => unwrap(api.post<ApiEnvelope<AssignmentDetail>>(`/admin/internship-assignments/${id}/activate`, { started_at })),
    complete: (id: number, remarks?: string, ended_at?: string) =>
      unwrap(api.post<ApiEnvelope<AssignmentDetail>>(`/admin/internship-assignments/${id}/complete`, { remarks, ended_at })),
    cancel: (id: number, reason: string) => unwrap(api.post<ApiEnvelope<AssignmentDetail>>(`/admin/internship-assignments/${id}/cancel`, { reason })),
  },

  excel: {
    upload: (type: ExcelImportType, file: File) => {
      const body = new FormData()
      body.append('type', type)
      body.append('file', file)
      return unwrap(api.post<ApiEnvelope<ExcelBatch>>('/admin/excel/import', body))
    },
    imports: (params: { page?: number; per_page?: number } = {}): List<ExcelBatch> =>
      unwrap(api.get<ApiEnvelope<PaginatedList<ExcelBatch>>>('/admin/excel/imports', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<ExcelBatch>>(`/admin/excel/imports/${id}`)),
    preview: (id: number, params: { page?: number; per_page?: number } = {}) =>
      unwrap(api.get<ApiEnvelope<{ batch: ExcelBatch; items: ExcelPreviewRow[]; meta: PaginatedList<never>['meta'] }>>(`/admin/excel/imports/${id}/preview`, { params })),
    /** Omit rowIds to apply every VALID/WARNING row; pass a subset to apply only those preview rows (ExcelPreviewRow.id). */
    confirm: (id: number, rowIds?: number[]) => unwrap(api.post<ApiEnvelope<ExcelConfirmResult>>(`/admin/excel/imports/${id}/confirm`, rowIds ? { row_ids: rowIds } : {})),
    rollback: (id: number) => unwrap(api.post<ApiEnvelope<ExcelRollbackResult>>(`/admin/excel/imports/${id}/rollback`, {})),
    template: (type: ExcelImportType) => api.get<Blob>(`/admin/excel/templates/${type}`, { responseType: 'blob' }).then((r) => r.data),
    export: (type: ExcelExportType, params: ExportFilters = {}) =>
      api.get<Blob>(`/admin/excel/exports/${type}`, { params, responseType: 'blob' }).then((r) => r.data),
  },

  students: {
    list: (params: StudentDirectoryParams = {}): List<AdminStudentRow> =>
      unwrap(api.get<ApiEnvelope<PaginatedList<AdminStudentRow>>>('/admin/students', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<AdminStudentDetail>>(`/admin/students/${id}`)),
    resume: (id: number) => api.get<Blob>(`/admin/students/${id}/resume`, { responseType: 'blob' }).then((r) => r.data),
    updateAcademics: (id: number, payload: { cgpa: number | null; backlogs: number | null; semester_label: string | null; reason: string }) =>
      api.post<ApiEnvelope<{ cgpa: number | null; backlogs: number | null; changed: boolean }>>(`/admin/students/${id}/academics`, payload).then((r) => ({ ...r.data.data, message: r.data.message })),
    resolveCorrection: (id: number, correctionId: number) => unwrap(api.post<ApiEnvelope<{ id: number; status: string }>>(`/admin/students/${id}/corrections/${correctionId}/resolve`)),
  },

  companies: {
    list: (params: { search?: string; industry?: string; status?: string; page?: number; per_page?: number } = {}) =>
      unwrap(api.get<ApiEnvelope<PaginatedList<CompanyRecord> & { industries: string[] }>>('/admin/companies', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<CompanyRecord>>(`/admin/companies/${id}`)),
    create: (payload: CompanyPayload) => unwrap(api.post<ApiEnvelope<CompanyRecord>>('/admin/companies', payload)),
    update: (id: number, payload: Partial<CompanyPayload>) => unwrap(api.patch<ApiEnvelope<CompanyRecord>>(`/admin/companies/${id}`, payload)),
  },

  reports: {
    dashboard: (params: ReportFilters = {}) =>
      unwrap(
        api.get<ApiEnvelope<{ placements: PlacementSummary; application_funnel: FunnelData; internships: InternshipReport }>>('/admin/reports/dashboard', { params }),
      ),
    placements: (params: ReportFilters & { dimension?: 'company' | 'department' | 'batch'; sort?: string; direction?: 'asc' | 'desc' } = {}) =>
      unwrap(api.get<ApiEnvelope<{ summary: PlacementSummary; breakdown: { dimension: string; rows: BreakdownRow[] } }>>('/admin/reports/placements', { params })),
    trends: (params: ReportFilters & { granularity?: 'day' | 'week' | 'month' } = {}) =>
      unwrap(api.get<ApiEnvelope<{ granularity: string; points: { period: string; applications: number }[] }>>('/admin/reports/trends', { params })),
    attendance: (params: ReportFilters & { threshold?: number } = {}) =>
      unwrap(
        api.get<ApiEnvelope<{ items: AttendanceReportRow[]; below_threshold: { threshold: number; rows: AttendanceReportRow[] } }>>('/admin/reports/attendance', { params }),
      ),
    faculty: (params: ReportFilters = {}) =>
      unwrap(api.get<ApiEnvelope<{ items: FacultyWorkloadRow[] }>>('/admin/reports/faculty', { params })).then((d) => d.items),
  },
}
