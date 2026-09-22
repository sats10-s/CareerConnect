import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { authService } from '@/services/authService'
import type { AuthUser, LoginPayload, RegisterPayload } from '@/types/auth'

interface AuthContextValue {
  user: AuthUser | null
  isLoading: boolean
  isAuthenticated: boolean
  login: (payload: LoginPayload) => Promise<AuthUser>
  register: (payload: RegisterPayload) => Promise<AuthUser>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

// eslint-disable-next-line react-refresh/only-export-components
export const AuthContext = createContext<AuthContextValue | undefined>(undefined)

/**
 * Owns the authenticated user's session state for the whole app.
 * On mount it asks the backend "who am I?" (GET /me) - this is what
 * lets a page refresh keep you logged in, since the source of truth
 * is the Sanctum session cookie, not anything stored client-side.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const refresh = useCallback(async () => {
    try {
      const me = await authService.me()
      setUser(me)
    } catch {
      setUser(null)
    }
  }, [])

  useEffect(() => {
    refresh().finally(() => setIsLoading(false))
  }, [refresh])

  const login = useCallback(async (payload: LoginPayload) => {
    const loggedInUser = await authService.login(payload)
    setUser(loggedInUser)
    return loggedInUser
  }, [])

  const register = useCallback(async (payload: RegisterPayload) => {
    const newUser = await authService.register(payload)
    setUser(newUser)
    return newUser
  }, [])

  const logout = useCallback(async () => {
    await authService.logout()
    setUser(null)
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({ user, isLoading, isAuthenticated: !!user, login, register, logout, refresh }),
    [user, isLoading, login, register, logout, refresh]
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
