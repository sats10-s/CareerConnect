import { forwardRef } from 'react'
import { ChevronDown } from 'lucide-react'
import type { SelectHTMLAttributes } from 'react'

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  error?: string
}

/** New shared primitive - see Input.tsx's docblock; same status. */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { error, className = '', children, ...rest },
  ref,
) {
  return (
    <div>
      <div className="relative">
        <select
          ref={ref}
          className={`input appearance-none pr-9 ${error ? 'border-error focus:border-error' : ''} ${className}`}
          aria-invalid={error ? true : undefined}
          {...rest}
        >
          {children}
        </select>
        <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
      </div>
      {error && <p className="mt-1 text-xs text-error">{error}</p>}
    </div>
  )
})
