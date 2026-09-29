import { useEffect } from 'react'
import { X } from 'lucide-react'
import type { ReactNode } from 'react'

interface DrawerProps {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  footer?: ReactNode
  side?: 'left' | 'right'
  /** Panel width: sm (24rem, default), md (28rem) or lg (36rem, for record detail with long text). Full-width on phones either way. */
  width?: 'sm' | 'md' | 'lg'
}

const WIDTH_CLASS = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-xl' } as const

/**
 * New shared primitive - slide-in panel anchored to a screen edge
 * (filters, detail-on-the-side, the mobile nav pattern). Mirrors
 * Modal's Escape/backdrop-close behavior; unlike Modal it's
 * edge-anchored and full-height rather than centered. Not wired into
 * any page yet.
 */
export function Drawer({ open, onClose, title, children, footer, side = 'right', width = 'sm' }: DrawerProps) {
  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  const sideClasses = side === 'right' ? 'right-0 animate-slide-in-right' : 'left-0 animate-slide-in-left'

  return (
    <div className="fixed inset-0 z-50 flex">
      <div className="fixed inset-0 bg-black/60" onClick={onClose} aria-hidden="true" />
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`card-elevated relative flex h-full w-full ${WIDTH_CLASS[width]} flex-col rounded-none border-y-0 ${side === 'right' ? 'border-r-0' : 'border-l-0'} ${sideClasses}`}
      >
        <div className="flex items-start justify-between gap-4 border-b border-hairline px-5 py-4">
          <h2 className="text-lg font-semibold text-ink">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="rounded-lg p-1.5 text-ink-soft transition-colors hover:bg-sidebar hover:text-ink"
          >
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
        {footer && <div className="flex justify-end gap-2 border-t border-hairline px-5 py-4">{footer}</div>}
      </div>
    </div>
  )
}
