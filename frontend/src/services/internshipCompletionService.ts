import { api } from './api'
import type { ApiEnvelope } from '@/types/common'
import type { InternshipCompletionDetail, PpoRecordDetail } from '@/types/internshipCompletion'

/**
 * GET /api/student/internship-completion and GET /api/student/ppo -
 * see Api\Student\InternshipCompletionController/PpoRecordController.
 * Both entirely read-only for a student (no create/update route
 * exists on either) - `data: null` is the ordinary state for most of
 * an internship's lifetime, not an error.
 */
export const internshipCompletionService = {
  completion: () =>
    api.get<ApiEnvelope<InternshipCompletionDetail | null>>('/student/internship-completion').then((r) => r.data.data),
  ppo: () => api.get<ApiEnvelope<PpoRecordDetail | null>>('/student/ppo').then((r) => r.data.data),
}
