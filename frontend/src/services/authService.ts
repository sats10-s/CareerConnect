import { api, ensureCsrfCookie } from './api'
import type { AuthUser, LoginPayload, RegisterPayload } from '@/types/auth'

export const authService = {
  async register(payload: RegisterPayload): Promise<AuthUser> {
    await ensureCsrfCookie()
    const { data } = await api.post('/auth/register', payload)
    return data.data as AuthUser
  },

  async login(payload: LoginPayload): Promise<AuthUser> {
    await ensureCsrfCookie()
    const { data } = await api.post('/auth/login', payload)
    return data.data as AuthUser
  },

  async logout(): Promise<void> {
    await api.post('/auth/logout')
  },

  async me(): Promise<AuthUser> {
    const { data } = await api.get('/auth/me')
    return data.data as AuthUser
  },
}
