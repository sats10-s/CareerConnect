import { forwardRef } from 'react'
import type { InputHTMLAttributes } from 'react'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  error?: string
}

/**
 * New shared primitive - named in the Institutional Ink component list
 * but did not exist in this codebase before this design-system pass
 * (raw <input> markup is used directly in RegisterPage/PersonalSection
 * instead). Built as a thin, token-driven wrapper around the existing
 * `.input` CSS class so those call sites have something to adopt in a
 * later, page-level pass. Not wired into any page yet.
 */
export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { error, className = '', ...rest },
  ref,
) {
  return (
    <div>
      <input
        ref={ref}
        className={`input ${error ? 'border-error focus:border-error' : ''} ${className}`}
        aria-invalid={error ? true : undefined}
        {...rest}
      />
      {error && <p className="mt-1 text-xs text-error">{error}</p>}
    </div>
  )
})
