/**
 * The full Student Profile module's data shapes - mirrors
 * App\Http\Resources\StudentProfileResource on the backend exactly.
 * Deliberately separate from types/auth.ts's StudentProfile (the
 * small dashboard/auth-boot shape) - this is the heavier,
 * profile-screen-specific payload returned by GET /student/profile
 * and by every mutation under it (see ReturnsFreshProfile on the
 * backend: every write returns the complete, freshly-loaded profile
 * so the frontend can replace its entire local state from any
 * response, never merge partial data client-side).
 */

export interface StudentSkill {
  id: number
  skill_name: string
}

export interface StudentProject {
  id: number
  title: string
  description: string | null
  technologies: string | null
  project_url: string | null
}

export interface StudentCertification {
  id: number
  name: string
  issuing_organization: string
  issue_date: string
  credential_url: string | null
}

export interface StudentExperience {
  id: number
  organization: string
  role: string
  start_date: string
  end_date: string | null
  description: string | null
}

export interface StudentResumeMeta {
  original_name: string
  size: number
  uploaded_at: string
}

export interface StudentProfileData {
  id: number
  name: string
  /** Staff-style login email - imported students have none (they sign in with their JNTU No). Never editable. */
  college_email: string | null
  personal_email: string | null
  phone: string | null

  must_change_password: boolean

  // --- College record (the 11 columns of the college's file) - read-only for the student. ---
  roll_number: string
  year_of_joining: number | null
  /** null when hidden from the viewer (faculty) or not recorded. */
  gender: 'female' | 'male' | null
  department_id: number | null
  department: string | null
  department_code: string | null
  section: string | null
  cgpa: number | null
  /** null = "not reported", never the same as 0. */
  backlogs: number | null
  ssc_score: number | null
  ssc_scale: 'percent' | 'cgpa10' | null
  inter_score: number | null
  inter_type: 'inter' | 'diploma' | null

  // --- Derived ---
  batch_id: number | null
  batch: string | null
  batch_start_year: number | null
  batch_end_year: number | null
  /** Current year of study (1-4), from the batch and the academic year setting. */
  year: number | null
  entry_type: 'regular' | 'lateral' | null
  program: 'btech' | 'mtech' | null
  academics_updated_at: string | null
  academic_history: AcademicHistoryEntry[]

  address: string | null

  skills: StudentSkill[]
  projects: StudentProject[]
  certifications: StudentCertification[]
  experiences: StudentExperience[]

  linkedin_url: string | null
  github_url: string | null
  portfolio_url: string | null

  /** Metadata only - never a URL. Download goes through the authenticated resume endpoint. */
  resume: StudentResumeMeta | null

  /** Deterministic, backend-calculated (ProfileCompletionCalculator) - never estimate this on the frontend. */
  profile_completion: number
  profile_checklist: ProfileChecklistItem[]
}

export interface AcademicHistoryEntry {
  id: number
  cgpa: number | null
  backlogs: number | null
  previous_cgpa: number | null
  previous_backlogs: number | null
  semester_label: string | null
  source: 'import' | 'manual'
  /** Staff viewers only - null for the student. */
  reason: string | null
  changed_by: string | null
  created_at: string
}

export interface ProfileChecklistItem {
  key: 'linkedin' | 'portfolio' | 'skills' | 'personal_email' | 'address' | 'resume'
  label: string
  weight: number
  done: boolean
}

/** PATCH /student/profile - only the student's own fields; send just the ones that changed. */
export interface UpdateStudentProfilePayload {
  personal_email?: string | null
  address?: string | null
  linkedin_url?: string | null
  github_url?: string | null
  portfolio_url?: string | null
}

export interface SkillPayload {
  skill_name: string
}

export interface ProjectPayload {
  title: string
  description?: string | null
  technologies?: string | null
  project_url?: string | null
}

export interface CertificationPayload {
  name: string
  issuing_organization: string
  issue_date: string
  credential_url?: string | null
}

export interface ExperiencePayload {
  organization: string
  role: string
  start_date: string
  end_date?: string | null
  description?: string | null
}
