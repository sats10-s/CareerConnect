import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { AttendanceStatus } from '@/types/attendance'
import type { InternshipAssignmentStatus } from '@/types/internshipAssignment'
import type { InternshipCompletionOutcome, PpoStatus, InternshipCompletionRecordStatus } from '@/types/internshipCompletion'
import type { InternshipDocumentStatus, InternshipDocumentType } from '@/types/internshipDocument'
import type { WeeklyReportStatus } from '@/types/weeklyReport'
import type {
  AssignmentAttendanceSummary,
  AssignmentDetail,
  AssignmentListItem,
  AttendanceRecord,
  AttendanceRecordDetail,
  CompletionFull,
  CompletionRow,
  DocumentFull,
  DocumentRow,
  FacultyStudentItem,
  FacultyWorkload,
  PpoFull,
  PpoRow,
  WeeklyReportFull,
  WeeklyReportRow,
} from '@/types/coordination'

/**
 * Every /api/faculty/* endpoint the Faculty screens use. Scoping is
 * entirely server-side: each of these only ever returns rows tied to
 * the signed-in faculty member's own internship assignments (see
 * InternshipAssignmentPolicy / FacultyStudentController) - there is
 * no faculty_id parameter to send, on purpose.
 */

type List<T> = Promise<PaginatedList<T>>
const unwrap = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data)

interface Paging {
  page?: number
  per_page?: number
  search?: string
}

export interface FacultyStudentParams extends Paging {
  status?: InternshipAssignmentStatus
  year?: number
  department_id?: number
}

export interface FacultyAssignmentParams extends Paging {
  status?: InternshipAssignmentStatus
  when?: 'upcoming' | 'current' | 'completed'
}

export interface AttendanceParams extends Paging {
  internship_assignment_id?: number
  status?: AttendanceStatus
  from_date?: string
  to_date?: string
}

export interface MarkAttendancePayload {
  attendance_date: string
  status: AttendanceStatus
  check_in_time?: string | null
  check_out_time?: string | null
  remarks?: string | null
}

export type UpdateAttendancePayload = Partial<Omit<MarkAttendancePayload, 'attendance_date'>>

export interface WeeklyReportParams extends Paging {
  internship_assignment_id?: number
  status?: WeeklyReportStatus
  week_number?: number
  from_date?: string
  to_date?: string
}

export type ReviewAction = 'UNDER_REVIEW' | 'APPROVED' | 'CHANGES_REQUESTED'

export interface DocumentParams extends Paging {
  internship_assignment_id?: number
  status?: InternshipDocumentStatus
  document_type?: InternshipDocumentType
  from_date?: string
  to_date?: string
}

export interface CompletionParams extends Paging {
  internship_assignment_id?: number
  outcome?: InternshipCompletionOutcome
  status?: InternshipCompletionRecordStatus
}

export interface CompletionPayload {
  completion_date: string
  outcome: InternshipCompletionOutcome
  final_remarks?: string | null
}

export interface PpoParams extends Paging {
  internship_assignment_id?: number
  status?: PpoStatus
}

export interface PpoPayload {
  offered_at: string
  designation: string
  joining_date?: string | null
  package?: number | null
  remarks?: string | null
}

export const facultyService = {
  students: (params: FacultyStudentParams = {}): List<FacultyStudentItem> =>
    unwrap(api.get<ApiEnvelope<PaginatedList<FacultyStudentItem>>>('/faculty/students', { params })),

  assignments: (params: FacultyAssignmentParams = {}): List<AssignmentListItem> =>
    unwrap(api.get<ApiEnvelope<PaginatedList<AssignmentListItem>>>('/faculty/internship-assignments', { params })),

  assignment: (id: number) => unwrap(api.get<ApiEnvelope<AssignmentDetail>>(`/faculty/internship-assignments/${id}`)),

  attendance: (params: AttendanceParams = {}): List<AttendanceRecord> =>
    unwrap(api.get<ApiEnvelope<PaginatedList<AttendanceRecord>>>('/faculty/attendance', { params })),

  assignmentAttendance: (assignmentId: number) =>
    unwrap(
      api.get<ApiEnvelope<{ summary: AssignmentAttendanceSummary; records: AttendanceRecord[] }>>(
        `/faculty/internship-assignments/${assignmentId}/attendance`,
      ),
    ),

  markAttendance: (assignmentId: number, payload: MarkAttendancePayload) =>
    unwrap(api.post<ApiEnvelope<AttendanceRecordDetail>>(`/faculty/internship-assignments/${assignmentId}/attendance`, payload)),

  updateAttendance: (id: number, payload: UpdateAttendancePayload) =>
    unwrap(api.patch<ApiEnvelope<AttendanceRecordDetail>>(`/faculty/attendance/${id}`, payload)),

  weeklyReports: (params: WeeklyReportParams = {}): List<WeeklyReportRow> =>
    unwrap(api.get<ApiEnvelope<PaginatedList<WeeklyReportRow>>>('/faculty/weekly-reports', { params })),

  weeklyReport: (id: number) => unwrap(api.get<ApiEnvelope<WeeklyReportFull>>(`/faculty/weekly-reports/${id}`)),

  reviewWeeklyReport: (id: number, action: ReviewAction, faculty_review_remarks?: string) =>
    unwrap(api.post<ApiEnvelope<WeeklyReportFull>>(`/faculty/weekly-reports/${id}/review`, { action, faculty_review_remarks })),

  documents: (params: DocumentParams = {}): List<DocumentRow> =>
    unwrap(api.get<ApiEnvelope<PaginatedList<DocumentRow>>>('/faculty/internship-documents', { params })),

  document: (id: number) => unwrap(api.get<ApiEnvelope<DocumentFull>>(`/faculty/internship-documents/${id}`)),

  reviewDocument: (id: number, status: ReviewAction, faculty_review_remarks?: string) =>
    unwrap(api.patch<ApiEnvelope<DocumentFull>>(`/faculty/internship-documents/${id}/status`, { status, faculty_review_remarks })),

  downloadDocument: (id: number) =>
    api.get<Blob>(`/faculty/internship-documents/${id}/download`, { responseType: 'blob' }).then((r) => r.data),

  completions: (params: CompletionParams = {}): List<CompletionRow> =>
    unwrap(api.get<ApiEnvelope<PaginatedList<CompletionRow>>>('/faculty/internship-completions', { params })),

  completion: (id: number) => unwrap(api.get<ApiEnvelope<CompletionFull>>(`/faculty/internship-completions/${id}`)),

  createCompletion: (assignmentId: number, payload: CompletionPayload) =>
    unwrap(api.post<ApiEnvelope<CompletionFull>>(`/faculty/internship-assignments/${assignmentId}/completion`, payload)),

  updateCompletion: (assignmentId: number, payload: Partial<CompletionPayload>) =>
    unwrap(api.patch<ApiEnvelope<CompletionFull>>(`/faculty/internship-assignments/${assignmentId}/completion`, payload)),

  finalizeCompletion: (id: number, final_remarks?: string) =>
    unwrap(api.post<ApiEnvelope<CompletionFull>>(`/faculty/internship-completions/${id}/finalize`, { final_remarks })),

  ppoRecords: (params: PpoParams = {}): List<PpoRow> =>
    unwrap(api.get<ApiEnvelope<PaginatedList<PpoRow>>>('/faculty/ppo-records', { params })),

  ppoRecord: (id: number) => unwrap(api.get<ApiEnvelope<PpoFull>>(`/faculty/ppo-records/${id}`)),

  createPpo: (assignmentId: number, payload: PpoPayload) =>
    unwrap(api.post<ApiEnvelope<PpoFull>>(`/faculty/internship-assignments/${assignmentId}/ppo`, payload)),

  updatePpo: (assignmentId: number, payload: Partial<PpoPayload> & { status?: 'ACCEPTED' | 'DECLINED' }) =>
    unwrap(api.patch<ApiEnvelope<PpoFull>>(`/faculty/internship-assignments/${assignmentId}/ppo`, payload)),

  summary: () => unwrap(api.get<ApiEnvelope<{ workload: FacultyWorkload }>>('/faculty/reports/summary')).then((d) => d.workload),
}
