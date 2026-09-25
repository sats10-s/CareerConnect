import type { ButtonHTMLAttributes } from 'react'
import type { LucideIcon } from 'lucide-react'

interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  icon: LucideIcon
  label: string
  badge?: boolean
}

/** A single icon-only control (topbar bell, menu toggle, close button) with a required accessible label - never icon-only with no text alternative. */
export function IconButton({ icon: Icon, label, badge, className = '', ...rest }: IconButtonProps) {
  return (
    <button
      type="button"
      aria-label={label}
      className={`relative rounded-lg p-2 text-ink-soft transition duration-fast ease-out hover:bg-sidebar hover:text-ink active:scale-[0.98] ${className}`}
      {...rest}
    >
      <Icon className="h-5 w-5" />
      {badge && (
        <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-gold" aria-hidden="true" />
      )}
    </button>
  )
}
