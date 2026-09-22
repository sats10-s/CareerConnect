import { NavLink } from 'react-router-dom'
import { GraduationCap } from 'lucide-react'
import type { NavItem } from '@/utils/navConfig'

export function Sidebar({ items, roleLabel }: { items: NavItem[]; roleLabel: string }) {
  return (
    <aside className="hidden w-64 shrink-0 flex-col border-r border-gray-200 bg-white lg:flex">
      <div className="flex h-16 items-center gap-2 border-b border-gray-100 px-5">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-navy-800 text-white">
          <GraduationCap className="h-4.5 w-4.5" strokeWidth={2.2} />
        </span>
        <div className="leading-tight">
          <p className="text-sm font-bold text-gray-900">CareerConnect</p>
          <p className="text-[11px] font-medium text-gray-400">{roleLabel}</p>
        </div>
      </div>

      <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
        {items.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
                isActive
                  ? 'bg-navy-800 text-white shadow-sm'
                  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
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
