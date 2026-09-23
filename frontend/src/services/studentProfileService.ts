import { api } from './api'
import type {
  CertificationPayload,
  ExperiencePayload,
  ProjectPayload,
  SkillPayload,
  StudentProfileData,
  UpdateStudentProfilePayload,
} from '@/types/studentProfile'

/**
 * The complete Student Profile module's API client - every route under
 * /student/profile (see backend routes/api.php). Every call here
 * returns the FULL, fresh profile (StudentProfileResource), matching
 * the backend's ReturnsFreshProfile design: the caller always replaces
 * its entire local profile state with the response, never merges a
 * partial shape in client-side.
 *
 * Supersedes the old narrow studentService (GET/PUT /student/profile
 * only, against the plain AuthUser.student shape) - this module's
 * profile screen needs the richer resource below.
 */
export const studentProfileService = {
  async getProfile(): Promise<StudentProfileData> {
    const { data } = await api.get('/student/profile')
    return data.data as StudentProfileData
  },

  async updateProfile(payload: UpdateStudentProfilePayload): Promise<StudentProfileData> {
    const { data } = await api.put('/student/profile', payload)
    return data.data as StudentProfileData
  },

  // --- Skills --------------------------------------------------------

  async addSkill(payload: SkillPayload): Promise<StudentProfileData> {
    const { data } = await api.post('/student/profile/skills', payload)
    return data.data as StudentProfileData
  },

  async deleteSkill(skillId: number): Promise<StudentProfileData> {
    const { data } = await api.delete(`/student/profile/skills/${skillId}`)
    return data.data as StudentProfileData
  },

  // --- Projects --------------------------------------------------------

  async addProject(payload: ProjectPayload): Promise<StudentProfileData> {
    const { data } = await api.post('/student/profile/projects', payload)
    return data.data as StudentProfileData
  },

  async updateProject(projectId: number, payload: ProjectPayload): Promise<StudentProfileData> {
    const { data } = await api.put(`/student/profile/projects/${projectId}`, payload)
    return data.data as StudentProfileData
  },

  async deleteProject(projectId: number): Promise<StudentProfileData> {
    const { data } = await api.delete(`/student/profile/projects/${projectId}`)
    return data.data as StudentProfileData
  },

  // --- Certifications --------------------------------------------------------

  async addCertification(payload: CertificationPayload): Promise<StudentProfileData> {
    const { data } = await api.post('/student/profile/certifications', payload)
    return data.data as StudentProfileData
  },

  async updateCertification(certificationId: number, payload: CertificationPayload): Promise<StudentProfileData> {
    const { data } = await api.put(`/student/profile/certifications/${certificationId}`, payload)
    return data.data as StudentProfileData
  },

  async deleteCertification(certificationId: number): Promise<StudentProfileData> {
    const { data } = await api.delete(`/student/profile/certifications/${certificationId}`)
    return data.data as StudentProfileData
  },

  // --- Experience --------------------------------------------------------

  async addExperience(payload: ExperiencePayload): Promise<StudentProfileData> {
    const { data } = await api.post('/student/profile/experiences', payload)
    return data.data as StudentProfileData
  },

  async updateExperience(experienceId: number, payload: ExperiencePayload): Promise<StudentProfileData> {
    const { data } = await api.put(`/student/profile/experiences/${experienceId}`, payload)
    return data.data as StudentProfileData
  },

  async deleteExperience(experienceId: number): Promise<StudentProfileData> {
    const { data } = await api.delete(`/student/profile/experiences/${experienceId}`)
    return data.data as StudentProfileData
  },

  // --- Resume --------------------------------------------------------

  async uploadResume(file: File): Promise<StudentProfileData> {
    const form = new FormData()
    form.append('resume', file)
    const { data } = await api.post('/student/profile/resume', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data.data as StudentProfileData
  },

  /** Streams the authenticated student's own resume as a Blob - there is no public resume URL to link to. */
  async downloadResume(): Promise<Blob> {
    const response = await api.get('/student/profile/resume', { responseType: 'blob' })
    return response.data as Blob
  },

  async deleteResume(): Promise<StudentProfileData> {
    const { data } = await api.delete('/student/profile/resume')
    return data.data as StudentProfileData
  },

  /**
   * PUT /student/profile always replaces roll_number/department_id/
   * batch_id/year/cgpa/backlogs/phone/personal_email/linkedin_url/
   * github_url/portfolio_url as a full set (see UpdateProfileRequest) -
   * it is not a partial patch. Sections that edit only a subset of
   * these fields (Personal, Academic, Links) build their payload from
   * the currently-loaded profile plus their own edits, so saving one
   * section never clobbers another's already-saved values.
   */
  toUpdatePayload(profile: StudentProfileData, overrides: Partial<UpdateStudentProfilePayload>): UpdateStudentProfilePayload {
    return {
      roll_number: profile.roll_number,
      department_id: profile.department_id ?? 0,
      batch_id: profile.batch_id ?? 0,
      year: profile.year ?? 0,
      cgpa: profile.cgpa,
      backlogs: profile.backlogs,
      phone: profile.phone,
      personal_email: profile.personal_email,
      linkedin_url: profile.linkedin_url,
      github_url: profile.github_url,
      portfolio_url: profile.portfolio_url,
      ...overrides,
    }
  },
}
