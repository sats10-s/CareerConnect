import type { LucideIcon } from 'lucide-react'
import { Construction } from 'lucide-react'

interface PlaceholderPageProps {
  title: string
  icon?: LucideIcon
}

/**
 * Renders every nav item that doesn't have real functionality yet
 * (see the project README - Excel sync, attendance, evaluations,
 * placement drives, etc. are intentionally out of scope for this
 * foundation and will be built in a later prompt).
 */
export function PlaceholderPage({ title, icon: Icon = Construction }: PlaceholderPageProps) {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center">
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-navy-50 text-navy-700">
        <Icon className="h-6 w-6" />
      </span>
      <h2 className="mt-4 text-lg font-semibold text-gray-900">{title}</h2>
      <p className="mt-1.5 max-w-sm text-sm text-gray-500">
        This section is scaffolded but not built out yet. It will be implemented in an upcoming update.
      </p>
    </div>
  )
}
