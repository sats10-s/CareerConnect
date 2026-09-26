import { useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'
import { MobileBottomNav } from '@/components/layout/MobileBottomNav'
import { NAV_ITEMS, MOBILE_PRIMARY_PATHS } from '@/utils/navConfig'
import { ROLE_LABEL } from '@/utils/roles'
import type { Role } from '@/types/auth'

/**
 * Shared chrome (sidebar + topbar + responsive mobile drawer) for all
 * four roles. Each role gets its own thin wrapper (StudentLayout,
 * FacultyLayout, AdminLayout, SuperAdminLayout) that just supplies
 * `role` - the actual layout logic lives here once.
 */
export function DashboardLayout({ role }: { role: Role }) {
  const [mobileOpen, setMobileOpen] = useState(false)
  const location = useLocation()
  const items = NAV_ITEMS[role]
  const activeLabel = items.find((item) => item.path === location.pathname)?.label ?? items[0]?.label ?? 'Dashboard'
  const mobilePrimaryPaths = MOBILE_PRIMARY_PATHS[role]
  const mobilePrimaryItems = mobilePrimaryPaths
    ? mobilePrimaryPaths.map((path) => items.find((item) => item.path === path)).filter((item): item is NonNullable<typeof item> => Boolean(item))
    : []

  return (
    <div className="flex h-screen overflow-hidden bg-base">
      <Sidebar items={items} roleLabel={ROLE_LABEL[role]} />

      {mobileOpen && (
        <div className="fixed inset-0 z-40 flex lg:hidden">
          <div className="fixed inset-0 bg-black/60" onClick={() => setMobileOpen(false)} />
          {/* Sidebar renders its own header (logo + role label + close
              button via onClose) - no separate drawer chrome needed on
              top of it, so the drawer is just the panel + shadow. */}
          <div className="relative shadow-xl">
            <Sidebar items={items} roleLabel={ROLE_LABEL[role]} variant="mobile" onClose={() => setMobileOpen(false)} />
          </div>
        </div>
      )}

      <div className="flex flex-1 flex-col overflow-hidden">
        <Topbar title={activeLabel} onMenuClick={() => setMobileOpen(true)} />
        <main className={`flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 ${mobilePrimaryItems.length > 0 ? 'pb-20 lg:pb-8' : ''}`}>
          <Outlet />
        </main>
      </div>

      <MobileBottomNav items={mobilePrimaryItems} />
    </div>
  )
}
