import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

interface EmptyStateProps {
  /** Legacy fallback - a lone Lucide icon in a filled circle. Prefer `illustration` (see components/illustrations/) per the design system; kept optional so call sites can migrate incrementally. */
  icon?: LucideIcon
  /** A custom line-illustration from the shared illustrations library - preferred over `icon`. Wins over `icon` when both are given. */
  illustration?: ReactNode
  title: string
  description?: string
  action?: ReactNode
}

export function EmptyState({ icon: Icon, illustration, title, description, action }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center px-6 py-12 text-center">
      {illustration ? (
        <div className="text-ink-faint">{illustration}</div>
      ) : (
        Icon && (
          <span className="flex h-12 w-12 items-center justify-center rounded-full bg-sidebar text-ink-faint">
            <Icon className="h-6 w-6" />
          </span>
        )
      )}
      <p className="mt-4 text-sm font-semibold text-ink">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-ink-soft">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}
