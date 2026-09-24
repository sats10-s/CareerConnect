import { useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

export type ToastTone = 'success' | 'warning' | 'error' | 'info'

interface ToastItem {
  id: number
  tone: ToastTone
  message: string
}

let listeners: Array<(item: ToastItem) => void> = []
let counter = 0

/**
 * New shared primitive - no toast/snackbar component existed in this
 * codebase before this design-system pass (a domain notification bell
 * in Topbar is unrelated). `toast()` can be called from anywhere;
 * mount a single `<ToastViewport />` (e.g. once near the app root) to
 * start rendering what it fires. Neither is wired in yet.
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
      }, 5000)
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
            className={`flex items-center gap-2 rounded-xl border border-hairline px-4 py-3 text-sm font-medium shadow-lg ${TONE_CLASSES[item.tone]}`}
          >
            <Icon className="h-4 w-4 shrink-0" />
            {item.message}
            <button
              type="button"
              onClick={() => setItems((prev) => prev.filter((existing) => existing.id !== item.id))}
              aria-label="Dismiss notification"
              className="ml-2"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
        )
      })}
    </div>
  )
}
