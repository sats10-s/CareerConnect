import { useEffect, useRef } from 'react'
import { useDialogFocus } from '@/hooks/useDialogFocus'
import { X } from 'lucide-react'
import type { ReactNode } from 'react'

interface ModalProps {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  footer?: ReactNode
}

/**
 * New shared primitive - no modal/dialog pattern existed in this
 * codebase before this design-system pass. Genuinely elevated (uses
 * `.card-elevated`, the one place a shadow is warranted per the shape
 * system), Escape-to-close, and backdrop-click-to-close. Not wired into
 * any page yet.
 */
export function Modal({ open, onClose, title, children, footer }: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  useDialogFocus(open, panelRef)

  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="fixed inset-0 bg-black/60" onClick={onClose} aria-hidden="true" />
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        ref={panelRef}
        tabIndex={-1}
        className="card-elevated relative max-h-[calc(100dvh-2rem)] w-full max-w-lg animate-fade-in overflow-y-auto p-6 focus:outline-none"
      >
        <div className="mb-4 flex items-start justify-between gap-4">
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
        {children}
        {footer && <div className="mt-6 flex justify-end gap-2">{footer}</div>}
      </div>
    </div>
  )
}
