import axios from 'axios'

/**
 * Base URL of the Laravel backend, e.g. http://localhost:8000.
 * The `/api` prefix is added per-request below.
 */
export const API_BASE_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

/**
 * Shared axios instance.
 *
 * `withCredentials: true` is required for Laravel Sanctum's
 * cookie-based SPA authentication (login/logout/me all rely on the
 * session cookie, not just the bearer token).
 */
export const api = axios.create({
  baseURL: `${API_BASE_URL}/api`,
  withCredentials: true,
  headers: {
    Accept: 'application/json',
  },
})

/**
 * Sanctum's stateful SPA auth requires the CSRF cookie to be fetched
 * once (from the app root, not /api) before any state-changing
 * request (login/register/logout).
 */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get(`${API_BASE_URL}/sanctum/csrf-cookie`, { withCredentials: true })
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    // Centralised place to react to auth expiry etc. Individual
    // callers still get the rejected promise to handle field errors.
    if (error?.response?.status === 401) {
      // Let the AuthContext decide what to do (e.g. clear user state);
      // we avoid a hard redirect here to keep this module UI-agnostic.
    }
    return Promise.reject(error)
  }
)
