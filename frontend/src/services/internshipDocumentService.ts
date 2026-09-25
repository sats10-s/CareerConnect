import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { InternshipDocumentDetail, InternshipDocumentListItem, InternshipDocumentType } from '@/types/internshipDocument'

export interface UploadDocumentPayload {
  internship_assignment_id: number
  document_type: InternshipDocumentType
  title: string
  file: File
  student_notes?: string
}

/**
 * /api/student/internship-documents - see
 * Api\Student\InternshipDocumentController. Content-based file
 * validation (pdf/doc/docx, 5MB max) happens server-side; download
 * always goes through the authenticated /download endpoint as a blob
 * - stored_filename/storage_path are never exposed in any JSON
 * response (see InternshipDocumentResource's docblock).
 */
export const internshipDocumentService = {
  forAssignment: (assignmentId: number) =>
    api
      .get<ApiEnvelope<PaginatedList<InternshipDocumentListItem>>>('/student/internship-documents', {
        params: { internship_assignment_id: assignmentId, per_page: 100 },
      })
      .then((r) => r.data.data.items),

  show: (id: number) =>
    api.get<ApiEnvelope<InternshipDocumentDetail>>(`/student/internship-documents/${id}`).then((r) => r.data.data),

  upload: (payload: UploadDocumentPayload) => {
    const form = new FormData()
    form.append('internship_assignment_id', String(payload.internship_assignment_id))
    form.append('document_type', payload.document_type)
    form.append('title', payload.title)
    form.append('file', payload.file)
    if (payload.student_notes) form.append('student_notes', payload.student_notes)
    return api
      .post<ApiEnvelope<InternshipDocumentDetail>>('/student/internship-documents', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data.data)
  },

  resubmit: (id: number, file: File, studentNotes?: string) => {
    const form = new FormData()
    form.append('file', file)
    if (studentNotes) form.append('student_notes', studentNotes)
    return api
      .post<ApiEnvelope<InternshipDocumentDetail>>(`/student/internship-documents/${id}/resubmit`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data.data)
  },

  download: (id: number) =>
    api.get<Blob>(`/student/internship-documents/${id}/download`, { responseType: 'blob' }).then((r) => r.data),
}
