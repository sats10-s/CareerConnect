import type { ReactNode } from 'react'

/**
 * Header for a hairline-divided section (applications, activity feed) -
 * deliberately NOT a Card/CardHeader. Per the design system, not every
 * section is a bordered card; list content gets a section header plus
 * a hairline divider under it instead of a full card wrapper. Reserve
 * actual card borders for genuinely discrete, comparable items (e.g.
 * opportunity listings).
 */
export function SectionHeader({
  title,
  subtitle,
  action,
}: {
  title: string
  subtitle?: string
  action?: ReactNode
}) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-border pb-3">
      <div>
        <h3 className="text-sm font-semibold text-ink">{title}</h3>
        {subtitle && <p className="mt-0.5 text-xs text-ink-faint">{subtitle}</p>}
      </div>
      {action}
    </div>
  )
}
