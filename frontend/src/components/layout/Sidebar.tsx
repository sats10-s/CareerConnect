import { NavLink } from 'react-router-dom'
import { GraduationCap } from 'lucide-react'
import type { NavItem } from '@/utils/navConfig'

/**
 * Light ivory sidebar with a hairline right border. Active nav state is
 * indigo text + a 2px gold left rule + a subtle surface background -
 * deliberately NOT a filled color pill, per the design system.
 */
export function Sidebar({
  items,
  roleLabel,
  variant = 'desktop',
}: {
  items: NavItem[]
  roleLabel: string
  /** 'desktop' hides below lg (the persistent rail); 'mobile' always renders flex (used inside the drawer, which already handles its own lg:hidden visibility). */
  variant?: 'desktop' | 'mobile'
}) {
  return (
    <aside
      className={`w-64 shrink-0 flex-col border-r border-border bg-sidebar ${
        variant === 'desktop' ? 'hidden lg:flex' : 'flex'
      }`}
    >
      <div className="flex h-16 items-center gap-2 border-b border-border px-5">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo text-white">
          <GraduationCap className="h-[18px] w-[18px]" strokeWidth={2.2} />
        </span>
        <div className="leading-tight">
          <p className="text-sm font-bold text-ink">CareerConnect</p>
          <p className="text-[11px] font-medium text-ink-faint">{roleLabel}</p>
        </div>
      </div>

      <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
        {items.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-r-lg border-l-2 py-2.5 pl-[10px] pr-3 text-sm font-medium transition-colors ${
                isActive
                  ? 'border-gold bg-surface text-indigo'
                  : 'border-transparent text-ink-soft hover:bg-surface/60 hover:text-ink'
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
