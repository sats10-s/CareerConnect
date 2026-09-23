import { useState } from 'react'
import { Bell, ChevronDown, LogOut, Menu } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { Avatar } from '@/components/ui/Avatar'
import { IconButton } from '@/components/ui/IconButton'

export function Topbar({ title, onMenuClick }: { title: string; onMenuClick?: () => void }) {
  const { user, logout } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <header className="flex h-16 items-center justify-between border-b border-border bg-surface px-4 sm:px-6">
      <div className="flex items-center gap-3">
        <IconButton icon={Menu} label="Open navigation" onClick={onMenuClick} className="lg:hidden" />
        <h1 className="text-base font-semibold text-ink sm:text-lg">{title}</h1>
      </div>

      <div className="flex items-center gap-2 sm:gap-3">
        <IconButton icon={Bell} label="Notifications" badge />

        <div className="relative">
          <button
            type="button"
            onClick={() => setMenuOpen((open) => !open)}
            className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-sidebar"
          >
            <Avatar name={user?.name} size="sm" />
            <span className="hidden text-left sm:block">
              <span className="block text-sm font-medium text-ink">{user?.name}</span>
            </span>
            <ChevronDown className="hidden h-4 w-4 text-ink-faint sm:block" />
          </button>

          {menuOpen && (
            <div className="absolute right-0 top-full z-20 mt-2 w-44 rounded-lg border border-border bg-surface py-1 shadow-lg">
              <button
                type="button"
                onClick={() => logout()}
                className="flex w-full items-center gap-2 px-3.5 py-2 text-sm text-ink-soft hover:bg-sidebar"
              >
                <LogOut className="h-4 w-4" />
                Log out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}
