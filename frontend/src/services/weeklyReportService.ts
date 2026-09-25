import { api } from './api'
import type { ApiEnvelope } from '@/types/common'
import type { WeeklyReportDetail, WeeklyReportListItem } from '@/types/weeklyReport'

export interface CreateWeeklyReportPayload {
  week_number: number
  week_start_date: string
  week_end_date: string
  work_done: string
  learning_outcomes?: string
  challenges?: string
  next_week_plan?: string
}

export type UpdateWeeklyReportPayload = Partial<Omit<CreateWeeklyReportPayload, 'week_number'>>

/**
 * /api/student/weekly-reports & /api/student/internship-assignments/{id}/weekly-reports
 * - see Api\Student\WeeklyProgressReportController. `create` always
 * starts a report at DRAFT server-side; `submit` takes no body at all
 * (all business validation happens in WeeklyProgressReportService).
 */
export const weeklyReportService = {
  forAssignment: (assignmentId: number) =>
    api
      .get<ApiEnvelope<{ records: WeeklyReportListItem[] }>>(`/student/internship-assignments/${assignmentId}/weekly-reports`)
      .then((r) => r.data.data.records),

  show: (id: number) => api.get<ApiEnvelope<WeeklyReportDetail>>(`/student/weekly-reports/${id}`).then((r) => r.data.data),

  create: (assignmentId: number, payload: CreateWeeklyReportPayload) =>
    api
      .post<ApiEnvelope<WeeklyReportDetail>>(`/student/internship-assignments/${assignmentId}/weekly-reports`, payload)
      .then((r) => r.data.data),

  update: (id: number, payload: UpdateWeeklyReportPayload) =>
    api.patch<ApiEnvelope<WeeklyReportDetail>>(`/student/weekly-reports/${id}`, payload).then((r) => r.data.data),

  submit: (id: number) =>
    api.post<ApiEnvelope<WeeklyReportDetail>>(`/student/weekly-reports/${id}/submit`).then((r) => r.data.data),
}
