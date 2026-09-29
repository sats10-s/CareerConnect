import { isAxiosError } from 'axios'

export interface ParsedApiError {
  /** A message that is safe to show to the user as-is. */
  message: string
  /** First validation message per field name (Laravel's `errors` bag), when the server sent one. */
  fieldErrors: Record<string, string>
  status?: number
}

interface LaravelErrorBody {
  message?: string
  errors?: Record<string, string[] | string> | unknown[]
}

/**
 * Turns any thrown value from an `api` call into something a page can
 * show. 4xx bodies from this backend carry real, human-readable domain
 * messages ("This report cannot move from SUBMITTED to APPROVED.") and
 * are passed through; 401/403/404/5xx and network failures get fixed
 * copy instead, because those bodies are either generic Laravel text
 * ("This action is unauthorized.") or - with APP_DEBUG on - exception
 * dumps that must never reach a screen.
 */
export function parseApiError(err: unknown, fallback = 'Something went wrong. Please try again.'): ParsedApiError {
  if (!isAxiosError(err)) return { message: fallback, fieldErrors: {} }

  if (!err.response) {
    return { message: 'Cannot reach the server. Check your connection and try again.', fieldErrors: {} }
  }

  const { status, data } = err.response as { status: number; data?: LaravelErrorBody }

  const fieldErrors: Record<string, string> = {}
  if (data?.errors && !Array.isArray(data.errors) && typeof data.errors === 'object') {
    for (const [field, value] of Object.entries(data.errors)) {
      const first = Array.isArray(value) ? value[0] : value
      if (first) fieldErrors[field] = String(first)
    }
  }

  if (status === 401) return { message: 'Your session has expired. Please sign in again.', fieldErrors, status }
  if (status === 403) return { message: "You don't have permission to do that.", fieldErrors, status }
  if (status === 404) return { message: 'That record no longer exists or was moved.', fieldErrors, status }
  if (status === 429) return { message: 'Too many requests. Please wait a moment and try again.', fieldErrors, status }
  if (status >= 500) return { message: 'The server ran into a problem. Please try again in a moment.', fieldErrors, status }

  return { message: data?.message || fallback, fieldErrors, status }
}

/**
 * The banner text for a failed form submit. When the server flagged
 * specific fields, those messages already sit under their inputs, so
 * the banner just points at them instead of repeating the first one
 * (Laravel's own top-level message is that same sentence plus an
 * "(and N more errors)" suffix). Domain-rule failures carry no field
 * errors and keep their own message.
 */
export function formBanner(parsed: ParsedApiError): string {
  return Object.keys(parsed.fieldErrors).length > 0 ? 'Please fix the highlighted fields.' : parsed.message
}

/**
 * Like parseApiError, for requests made with `responseType: 'blob'`: on
 * failure axios hands back the JSON error body as a Blob, which has to
 * be read asynchronously before its validation message is available.
 */
export async function parseBlobApiError(err: unknown, fallback = 'Something went wrong. Please try again.'): Promise<ParsedApiError> {
  if (isAxiosError(err) && err.response && err.response.data instanceof Blob) {
    try {
      const body = JSON.parse(await err.response.data.text()) as LaravelErrorBody
      return parseApiError({ ...err, response: { ...err.response, data: body }, isAxiosError: true }, fallback)
    } catch {
      /* not JSON - fall through to the status-based copy */
    }
  }
  return parseApiError(err, fallback)
}
