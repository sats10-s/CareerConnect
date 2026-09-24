import { forwardRef } from 'react'
import type { TextareaHTMLAttributes } from 'react'

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  error?: string
}

/** New shared primitive - see Input.tsx's docblock; same status. */
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { error, className = '', rows = 4, ...rest },
  ref,
) {
  return (
    <div>
      <textarea
        ref={ref}
        rows={rows}
        className={`input resize-y ${error ? 'border-error focus:border-error' : ''} ${className}`}
        aria-invalid={error ? true : undefined}
        {...rest}
      />
      {error && <p className="mt-1 text-xs text-error">{error}</p>}
    </div>
  )
})
