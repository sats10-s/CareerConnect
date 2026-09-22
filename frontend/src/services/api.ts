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
 *
 * `withXSRFToken: true` is required too, separately: the frontend
 * (localhost:5173) and backend (localhost:8000) are different ports,
 * which makes every request here cross-origin. Since axios 1.6, it
 * only reads the XSRF-TOKEN cookie and sends it back as the
 * X-XSRF-TOKEN header automatically for same-origin requests - for
 * anything cross-origin it stays silent unless this is set, even
 * though the cookie itself was fetched successfully. Without it,
 * every state-changing request (login/register/logout) has no CSRF
 * token attached at all, so Sanctum's stateful CSRF check rejects it
 * with 419 - a plain GET like /auth/me isn't CSRF-checked, which is
 * why "read" calls appeared to work while login didn't.
 */
export const api = axios.create({
  baseURL: `${API_BASE_URL}/api`,
  withCredentials: true,
  withXSRFToken: true,
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

  // TEMPORARY DIAGNOSTIC - remove once the browser login flow is
  // confirmed working. XSRF-TOKEN is intentionally not httpOnly (the
  // whole point of Sanctum's double-submit pattern is that JS can read
  // it), so this tells us directly whether the cookie actually made it
  // into the browser's cookie jar for this origin, independent of
  // whether axios later attaches it as a header.
  if (import.meta.env.DEV) {
    const hasXsrfCookie = document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='))
    // eslint-disable-next-line no-console
    console.log('[careerconnect:diag] after /sanctum/csrf-cookie, document.cookie has XSRF-TOKEN:', hasXsrfCookie, '| full document.cookie:', document.cookie)
  }
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

    // TEMPORARY DIAGNOSTIC - remove once the browser login flow is
    // confirmed working. Logs exactly what happened on the wire for
    // any failed request through this instance, instead of letting the
    // UI collapse it into a generic message:
    //  - error.response present  -> the server answered; status + body
    //    are the ground truth for what actually went wrong.
    //  - error.response absent, error.request present -> the browser
    //    never got a response at all (CORS rejection, connection
    //    refused, DNS failure, etc.) - this is NOT the same failure as
    //    a 419/422/500 and needs a different fix.
    if (import.meta.env.DEV) {
      if (error?.response) {
        // eslint-disable-next-line no-console
        console.error('[careerconnect:diag] request failed WITH a response', {
          url: error.config?.url,
          method: error.config?.method,
          status: error.response.status,
          statusText: error.response.statusText,
          responseData: error.response.data,
          responseHeaders: error.response.headers,
          requestHeadersSent: error.config?.headers,
        })
      } else if (error?.request) {
        // eslint-disable-next-line no-console
        console.error('[careerconnect:diag] request failed with NO response (network/CORS-level failure)', {
          url: error.config?.url,
          method: error.config?.method,
          withCredentials: error.config?.withCredentials,
          withXSRFToken: error.config?.withXSRFToken,
          message: error.message,
          code: error.code,
        })
      } else {
        // eslint-disable-next-line no-console
        console.error('[careerconnect:diag] request setup failed before it was sent', error)
      }
    }

    return Promise.reject(error)
  }
)
