import { NavLink } from 'react-router-dom'
import { GraduationCap, X } from 'lucide-react'
import type { NavItem } from '@/utils/navConfig'

/**
 * Dark-pass rewrite: this used to be a solid forest-green filled panel
 * with white/white-opacity text, deliberately built around the old
 * "Institutional Ink" dark forest primary. Once primary became a
 * bright mint accent (the twinz-style dark rebuild), a full-panel mint
 * fill with white text was a wash-out mismatch (bright mint bg + pale
 * white/65 text = poor contrast, and a neon-green sidebar next to a
 * near-black content area reads as broken, not on-brand) - caught from
 * a live screenshot showing exactly that. Sidebar is now dark (the
 * --color-sidebar token, a hair lighter than the page base so it stays
 * visually distinct with only a hairline to separate it), with mint
 * reserved for the active nav item and icon accents - matching how
 * the rest of the app already treats mint as a single accent, not a
 * fill color for large surfaces.
 */
export function Sidebar({
  items,
  roleLabel,
  variant = 'desktop',
  onClose,
}: {
  items: NavItem[]
  roleLabel: string
  /** 'desktop' hides below lg (the persistent rail); 'mobile' always renders flex (used inside the drawer, which already handles its own lg:hidden visibility). */
  variant?: 'desktop' | 'mobile'
  /** Only used by the mobile drawer - renders a close (X) button in the header. */
  onClose?: () => void
}) {
  return (
    <aside
      className={`w-60 shrink-0 flex-col border-r border-hairline bg-sidebar ${variant === 'desktop' ? 'hidden lg:flex' : 'flex'}`}
    >
      <div className="flex h-16 items-center gap-2 border-b border-hairline px-5">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-[color:var(--color-primary-ink)]">
          <GraduationCap className="h-[18px] w-[18px]" strokeWidth={2.2} />
        </span>
        <div className="min-w-0 flex-1 leading-tight">
          <p className="truncate font-display text-sm font-bold text-ink">CareerConnect</p>
          <p className="truncate text-[11px] font-medium text-faint">{roleLabel}</p>
        </div>
        {onClose && (
          <button
            type="button"
            onClick={onClose}
            aria-label="Close navigation"
            className="rounded-lg p-1.5 text-muted transition-colors duration-fast ease-out hover:bg-surface-raised hover:text-ink"
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </div>

      <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
        {items.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            onClick={onClose}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-r-lg border-l-2 py-2.5 pl-[10px] pr-3 text-sm font-medium transition-colors duration-fast ease-out ${
                isActive
                  ? 'border-primary bg-primary-tint text-primary'
                  : 'border-transparent text-muted hover:bg-surface-raised hover:text-ink'
              }`
            }
          >
            <item.icon className="h-[18px] w-[18px] shrink-0" />
            {item.label}
          </NavLink>
        ))}
      </nav>
    </aside>
  )
}
