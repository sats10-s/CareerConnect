import { useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

export type ToastTone = 'success' | 'warning' | 'error' | 'info'

interface ToastItem {
  id: number
  tone: ToastTone
  message: string
}

const DISMISS_MS = 5000

let listeners: Array<(item: ToastItem) => void> = []
let counter = 0

/**
 * New shared primitive - no toast/snackbar component existed in this
 * codebase before this design-system pass (a domain notification bell
 * in Topbar is unrelated). `toast()` can be called from anywhere;
 * mount a single `<ToastViewport />` (e.g. once near the app root) to
 * start rendering what it fires.
 */
export function toast(tone: ToastTone, message: string) {
  const item: ToastItem = { id: ++counter, tone, message }
  listeners.forEach((listener) => listener(item))
}

const ICONS: Record<ToastTone, LucideIcon> = {
  success: CheckCircle2,
  warning: AlertTriangle,
  error: XCircle,
  info: Info,
}

const TONE_CLASSES: Record<ToastTone, string> = {
  success: 'bg-success-bg text-success',
  warning: 'bg-warning-bg text-warning',
  error: 'bg-error-bg text-error',
  info: 'bg-info-bg text-info',
}

export function ToastViewport() {
  const [items, setItems] = useState<ToastItem[]>([])

  useEffect(() => {
    const onNew = (item: ToastItem) => {
      setItems((prev) => [...prev, item])
      window.setTimeout(() => {
        setItems((prev) => prev.filter((existing) => existing.id !== item.id))
      }, DISMISS_MS)
    }
    listeners.push(onNew)
    return () => {
      listeners = listeners.filter((listener) => listener !== onNew)
    }
  }, [])

  if (items.length === 0) return null

  return (
    <div className="fixed bottom-4 right-4 z-50 flex flex-col gap-2" aria-live="polite" role="status">
      {items.map((item) => {
        const Icon = ICONS[item.tone]
        return (
          <div
            key={item.id}
            className={`animate-toast-in relative flex items-center gap-2 overflow-hidden rounded-xl border border-hairline px-4 py-3 text-sm font-medium shadow-lg ${TONE_CLASSES[item.tone]}`}
          >
            <Icon className="h-4 w-4 shrink-0" />
            {item.message}
            <button
              type="button"
              onClick={() => setItems((prev) => prev.filter((existing) => existing.id !== item.id))}
              aria-label="Dismiss notification"
              className="ml-2 transition-transform duration-fast ease-out active:scale-[0.9]"
            >
              <X className="h-3.5 w-3.5" />
            </button>
            <DismissTimer />
          </div>
        )
      })}
    </div>
  )
}

/**
 * Visible auto-dismiss countdown - per the motion system spec ("Toasts:
 * slide+fade in from the edge, auto-dismiss with a visible timer/
 * progress sliver, not an abrupt disappearance"). A thin bar that
 * shrinks from 100% to 0% over the same DISMISS_MS the toast actually
 * lives for, so the warning is accurate, not decorative.
 */
function DismissTimer() {
  const [collapsed, setCollapsed] = useState(false)

  useEffect(() => {
    const frame = requestAnimationFrame(() => setCollapsed(true))
    return () => cancelAnimationFrame(frame)
  }, [])

  return (
    <div className="absolute inset-x-0 bottom-0 h-0.5 bg-black/10" aria-hidden="true">
      <div
        className="h-full bg-current opacity-40 transition-[width] ease-linear"
        style={{ width: collapsed ? '0%' : '100%', transitionDuration: `${DISMISS_MS}ms` }}
      />
    </div>
  )
}
