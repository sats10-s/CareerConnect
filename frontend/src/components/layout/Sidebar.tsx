import { NavLink } from 'react-router-dom'
import { GraduationCap, X } from 'lucide-react'
import type { NavItem } from '@/utils/navConfig'

/**
 * Frontend Phase 1: deep forest-green filled sidebar (per the visual
 * reference), replacing the previous light-ivory rail. ~240px wide
 * (top of the spec's 220-240px range). Active state is now shown on
 * the dark panel via the orange accent (icon/text color + a soft
 * orange-tinted background + a left indicator bar) rather than the
 * old indigo-text-on-light-background treatment, which would be
 * invisible against a solid dark fill.
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
      className={`w-60 shrink-0 flex-col bg-primary ${variant === 'desktop' ? 'hidden lg:flex' : 'flex'}`}
    >
      <div className="flex h-16 items-center gap-2 border-b border-white/10 px-5">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10 text-accent">
          <GraduationCap className="h-[18px] w-[18px]" strokeWidth={2.2} />
        </span>
        <div className="min-w-0 flex-1 leading-tight">
          <p className="truncate text-sm font-bold text-white">CareerConnect</p>
          <p className="truncate text-[11px] font-medium text-white/55">{roleLabel}</p>
        </div>
        {onClose && (
          <button
            type="button"
            onClick={onClose}
            aria-label="Close navigation"
            className="rounded-lg p-1.5 text-white/70 hover:bg-white/10 hover:text-white"
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
              `flex items-center gap-3 rounded-r-lg border-l-2 py-2.5 pl-[10px] pr-3 text-sm font-medium transition-colors duration-150 ${
                isActive
                  ? 'border-accent bg-accent/12 text-accent'
                  : 'border-transparent text-white/65 hover:bg-white/5 hover:text-white'
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
