import { useId, useState } from 'react'
import type { ReactNode } from 'react'

/**
 * New shared primitive - minimal hover/focus tooltip. Kept deliberately
 * simple (no portal/collision-detection library) since nothing in the
 * app needs viewport-aware positioning yet; defaults to opening above
 * the trigger. Not wired into any page yet.
 */
export function Tooltip({ label, children, side = 'top' }: { label: string; children: ReactNode; side?: 'top' | 'bottom' }) {
  const [visible, setVisible] = useState(false)
  const id = useId()

  return (
    <span
      className="relative inline-flex"
      onMouseEnter={() => setVisible(true)}
      onMouseLeave={() => setVisible(false)}
      onFocus={() => setVisible(true)}
      onBlur={() => setVisible(false)}
    >
      <span aria-describedby={visible ? id : undefined}>{children}</span>
      {visible && (
        <span
          id={id}
          role="tooltip"
          className={`pointer-events-none absolute left-1/2 z-50 w-max max-w-[14rem] -translate-x-1/2 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-medium text-[color:var(--color-primary-ink)] shadow-lg transition-opacity duration-150 ${
            side === 'top' ? 'bottom-full mb-2' : 'top-full mt-2'
          }`}
        >
          {label}
        </span>
      )}
    </span>
  )
}
