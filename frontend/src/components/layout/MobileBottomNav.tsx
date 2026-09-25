import { NavLink } from 'react-router-dom'
import type { NavItem } from '@/utils/navConfig'

/**
 * Fixed bottom navigation bar for small screens - per spec, the mobile
 * shell should be intentionally designed (topbar -> content -> bottom
 * nav), not the desktop sidebar shrunk down. Shows a curated subset of
 * a role's nav (see navConfig.ts's MOBILE_PRIMARY_PATHS); the full nav
 * (including anything left out here) stays reachable through the
 * existing hamburger drawer. Hidden at `lg` and up, where the desktop
 * sidebar takes over.
 */
export function MobileBottomNav({ items }: { items: NavItem[] }) {
  if (items.length === 0) return null

  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-30 flex items-stretch justify-around border-t border-hairline bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden"
      aria-label="Primary"
    >
      {items.map((item) => (
        <NavLink
          key={item.path}
          to={item.path}
          className={({ isActive }) =>
            `flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium transition-colors duration-150 ${
              isActive ? 'text-primary' : 'text-muted'
            }`
          }
        >
          {({ isActive }) => (
            <>
              <item.icon className="h-5 w-5" strokeWidth={isActive ? 2.4 : 2} />
              <span className="truncate">{item.label}</span>
            </>
          )}
        </NavLink>
      ))}
    </nav>
  )
}
