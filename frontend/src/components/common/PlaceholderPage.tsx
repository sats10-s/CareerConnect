import type { LucideIcon } from 'lucide-react'
import { Construction } from 'lucide-react'

interface PlaceholderPageProps {
  title: string
  icon?: LucideIcon
  /** Why this section isn't built - what the backend does or doesn't offer. */
  note?: string
}

/**
 * Marks a nav item whose backend doesn't exist yet. It is deliberately
 * honest: it says the section isn't built and, where known, why, rather
 * than pretending to be a working page.
 */
export function PlaceholderPage({ title, icon: Icon = Construction, note }: PlaceholderPageProps) {
  return (
    <div className="card flex min-h-[50vh] flex-col items-center justify-center border-dashed p-8 text-center sm:p-12">
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-primary-tint text-primary">
        <Icon className="h-6 w-6" />
      </span>
      <p className="badge-eyebrow mt-5">Not available yet</p>
      <h2 className="mt-3 font-display text-lg font-semibold text-ink">{title}</h2>
      <p className="mt-2 max-w-md text-sm text-ink-soft">{note ?? "This section hasn't been built yet."}</p>
    </div>
  )
}
