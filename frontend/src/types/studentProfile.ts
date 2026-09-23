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
  /** The login identity - immutable from this module. Never render an edit control for this field. */
  college_email: string
  personal_email: string | null
  phone: string | null

  roll_number: string
  department_id: number | null
  department: string | null
  batch_id: number | null
  batch: string | null
  year: number | null
  cgpa: number | null
  backlogs: number | null

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
}

/**
 * PUT /student/profile is a full replace of these fields (not a
 * partial patch) - every section that edits a subset of them must
 * send the rest unchanged. See studentProfileService.toUpdatePayload.
 */
export interface UpdateStudentProfilePayload {
  roll_number: string
  department_id: number
  batch_id: number
  year: number
  cgpa?: number | null
  backlogs?: number | null
  phone?: string | null
  personal_email?: string | null
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
