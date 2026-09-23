import { api } from './api'
import type { AuthUser, StudentProfileUpdatePayload } from '@/types/auth'

/**
 * A student's own editable profile - distinct from authService.me(),
 * which is the app-boot "who am I" check. This is specifically the
 * GET/PUT /student/profile pair the Profile page uses to view and
 * edit roll_number/department/batch/year/cgpa/backlogs/phone.
 */
export const studentService = {
  async getProfile(): Promise<AuthUser> {
    const { data } = await api.get('/student/profile')
    return data.data as AuthUser
  },

  async updateProfile(payload: StudentProfileUpdatePayload): Promise<AuthUser> {
    const { data } = await api.put('/student/profile', payload)
    return data.data as AuthUser
  },
}
