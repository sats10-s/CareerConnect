import { ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'

export interface BreadcrumbItem {
  label: string
  path?: string
}

/** New shared primitive - not wired into any page yet (current pages are flat enough that a page title has sufficed so far). Available for deeper pages (e.g. an opportunity detail reached from a filtered list). */
export function Breadcrumbs({ items }: { items: BreadcrumbItem[] }) {
  return (
    <nav aria-label="Breadcrumb" className="flex items-center gap-1.5 text-sm text-muted">
      {items.map((item, index) => {
        const isLast = index === items.length - 1
        return (
          <span key={`${item.label}-${index}`} className="flex items-center gap-1.5">
            {index > 0 && <ChevronRight className="h-3.5 w-3.5 text-hairline" aria-hidden="true" />}
            {item.path && !isLast ? (
              <Link to={item.path} className="hover:text-ink">
                {item.label}
              </Link>
            ) : (
              <span className={isLast ? 'font-medium text-ink' : undefined} aria-current={isLast ? 'page' : undefined}>
                {item.label}
              </span>
            )}
          </span>
        )
      })}
    </nav>
  )
}
