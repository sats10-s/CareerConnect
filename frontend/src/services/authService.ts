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
    const response = await api.post('/auth/login', payload)

    // The backend wraps the user in a Laravel API resource envelope:
    // { data: { id, name, email, role, is_active, ... }, token }.
    // Extract explicitly and fail loudly if that shape isn't what came
    // back, instead of silently returning `undefined` to the caller -
    // an unhandled-shape bug here should surface as a clear error, not
    // as a downstream "Cannot read properties of undefined" crash.
    const user = response.data?.data as AuthUser | undefined
    if (!user) {
      throw new Error(
        `Login response did not include a user object. Received: ${JSON.stringify(response.data)}`
      )
    }
    return user
  },

  async logout(): Promise<void> {
    await api.post('/auth/logout')
  },

  async me(): Promise<AuthUser> {
    const { data } = await api.get('/auth/me')
    return data.data as AuthUser
  },
}
