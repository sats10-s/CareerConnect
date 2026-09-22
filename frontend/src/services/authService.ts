import { api, ensureCsrfCookie } from './api'
import type { AuthUser, LoginPayload, RegisterPayload } from '@/types/auth'

export const authService = {
  async register(payload: RegisterPayload): Promise<AuthUser> {
    await ensureCsrfCookie()
    const { data } = await api.post('/auth/register', payload)
    return data.data as AuthUser
  },

  async login(payload: LoginPayload): Promise<AuthUser> {
    // TEMPORARY DIAGNOSTIC - remove once the browser login flow is
    // confirmed working.
    if (import.meta.env.DEV) console.log('[careerconnect:diag] authService.login: calling ensureCsrfCookie()')
    await ensureCsrfCookie()
    if (import.meta.env.DEV) console.log('[careerconnect:diag] authService.login: ensureCsrfCookie() resolved, calling api.post(/auth/login)')
    const response = await api.post('/auth/login', payload)
    if (import.meta.env.DEV) console.log('[careerconnect:diag] authService.login: api.post(/auth/login) resolved', { status: response.status, body: response.data })
    const { data } = response
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
