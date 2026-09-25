export type InternshipDocumentType =
  | 'OFFER_LETTER'
  | 'JOINING_LETTER'
  | 'INTERNSHIP_CERTIFICATE'
  | 'WORK_EVIDENCE'
  | 'FINAL_REPORT'
  | 'COMPLETION_CERTIFICATE'
  | 'OTHER'

export type InternshipDocumentStatus = 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'CHANGES_REQUESTED'

/** Mirrors App\Http\Resources\InternshipDocumentListResource, minus the student/faculty/internship summary block (own data - redundant). Never carries stored_filename/storage_path - see that Resource's docblock on why. */
export interface InternshipDocumentListItem {
  id: number
  internship_assignment_id: number
  document_type: InternshipDocumentType
  title: string
  original_filename: string
  file_size: number
  status: InternshipDocumentStatus
  submitted_at: string | null
  reviewed_at: string | null
}

/** Mirrors the base (non-admin/faculty) fields of InternshipDocumentResource. */
export interface InternshipDocumentDetail extends InternshipDocumentListItem {
  student_notes: string | null
  faculty_review_remarks: string | null
  approved_at: string | null
  changes_requested_at: string | null
}
