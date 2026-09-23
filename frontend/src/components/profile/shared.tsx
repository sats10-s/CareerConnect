import { isAxiosError } from 'axios'
import type { ApiValidationError } from '@/types/auth'

/**
 * Shared bits used by every Profile-module section: consistent
 * inline success/error banners and a uniform way to read a Laravel
 * 422 validation-error response, so each section's error handling
 * looks the same rather than five slightly different implementations.
 */

export function SuccessBanner({ message }: { message: string }) {
  return (
    <div className="rounded-lg border border-success bg-success-bg px-3.5 py-2.5 text-sm text-success" role="status">
      {message}
    </div>
  )
}

export function ErrorBanner({ message }: { message: string }) {
  return (
    <div className="rounded-lg border border-error bg-error-bg px-3.5 py-2.5 text-sm text-error" role="alert">
      {message}
    </div>
  )
}

export function FieldError({ message }: { message: string | undefined }) {
  if (!message) return null
  return <p className="mt-1 text-xs text-error">{message}</p>
}

export interface FormFailure {
  message: string
  errors: Record<string, string[]>
}

/** Normalizes any failed profile-module API call into a message + field-error map, whether it was a 422 or something else. */
export function describeFailure(err: unknown, fallback: string): FormFailure {
  if (isAxiosError<ApiValidationError>(err) && err.response?.status === 422) {
    return {
      message: err.response.data.message ?? fallback,
      errors: err.response.data.errors ?? {},
    }
  }
  return { message: fallback, errors: {} }
}

export function fieldError(errors: Record<string, string[]>, key: string): string | undefined {
  return errors[key]?.[0]
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
