import { useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { X } from 'lucide-react'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'
import { NAV_ITEMS } from '@/utils/navConfig'
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

  return (
    <div className="flex h-screen overflow-hidden bg-gray-50">
      <Sidebar items={items} roleLabel={ROLE_LABEL[role]} />

      {mobileOpen && (
        <div className="fixed inset-0 z-40 flex lg:hidden">
          <div className="fixed inset-0 bg-gray-900/50" onClick={() => setMobileOpen(false)} />
          <div className="relative flex w-64 flex-col bg-white shadow-xl">
            <div className="flex h-16 items-center justify-between border-b border-gray-100 px-4">
              <span className="text-sm font-bold text-gray-900">CareerConnect</span>
              <button onClick={() => setMobileOpen(false)} className="rounded-lg p-1.5 hover:bg-gray-100" aria-label="Close navigation">
                <X className="h-5 w-5" />
              </button>
            </div>
            <Sidebar items={items} roleLabel={ROLE_LABEL[role]} />
          </div>
        </div>
      )}

      <div className="flex flex-1 flex-col overflow-hidden">
        <Topbar title={activeLabel} onMenuClick={() => setMobileOpen(true)} />
        <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
