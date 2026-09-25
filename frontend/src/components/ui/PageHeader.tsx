import type { ReactNode } from 'react'
import { Breadcrumbs, type BreadcrumbItem } from './Breadcrumbs'

interface PageHeaderProps {
  title: string
  description?: string
  breadcrumbs?: BreadcrumbItem[]
  action?: ReactNode
}

/**
 * New shared primitive - a full page-level header (breadcrumbs + title
 * + description + a primary action), distinct from SectionHeader
 * (which is for a hairline-divided section within a page, not the
 * page itself). Not wired into any page yet - most pages currently
 * build their own top row inline; this is available for a later pass
 * to consolidate onto, and for new pages (e.g. an opportunity detail
 * page) to start from directly.
 */
export function PageHeader({ title, description, breadcrumbs, action }: PageHeaderProps) {
  return (
    <div className="mb-6">
      {breadcrumbs && breadcrumbs.length > 0 && <div className="mb-2">
        <Breadcrumbs items={breadcrumbs} />
      </div>}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-ink sm:text-[28px]">{title}</h1>
          {description && <p className="mt-1 text-sm text-muted">{description}</p>}
        </div>
        {action}
      </div>
    </div>
  )
}
