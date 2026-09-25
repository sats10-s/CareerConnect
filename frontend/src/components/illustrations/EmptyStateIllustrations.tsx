/**
 * Shared empty-state line-illustration library - per the design
 * system: "one small custom line-illustration... never a lone Lucide
 * icon centered on a blank card, never a stock illustration pack or
 * cartoon mascot." Deliberately simple/geometric single-stroke SVGs
 * (not detailed art) so they read as part of this product, not a
 * downloaded icon set - and deliberately `currentColor`, so the
 * caller's wrapping text color (EmptyState renders them inside a
 * `text-ink-faint` block) controls their tone rather than each one
 * carrying its own hardcoded color.
 *
 * Kept as one file/library per the spec ("one shared component
 * library... do not hand-roll illustrations separately per screen")
 * rather than a component per empty state.
 */
import type { SVGProps } from 'react'

const STROKE = {
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.5,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
}

function IllustrationSvg({ className = 'h-16 w-16', children }: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 96 96" className={className} aria-hidden="true">
      {children}
    </svg>
  )
}

/** Applications / opportunities with nothing in them yet. */
export function EmptyBriefcaseIllustration(props: SVGProps<SVGSVGElement>) {
  return (
    <IllustrationSvg {...props}>
      <rect x="18" y="34" width="60" height="40" rx="6" {...STROKE} />
      <path d="M34 34v-8a6 6 0 0 1 6-6h16a6 6 0 0 1 6 6v8" {...STROKE} />
      <path d="M18 50h60" {...STROKE} />
      <rect x="42" y="46" width="12" height="10" rx="2" {...STROKE} />
    </IllustrationSvg>
  )
}

/** No results for a search/filter combination. */
export function EmptySearchIllustration(props: SVGProps<SVGSVGElement>) {
  return (
    <IllustrationSvg {...props}>
      <circle cx="42" cy="42" r="22" {...STROKE} />
      <path d="M58 58l14 14" {...STROKE} />
      <path d="M33 42h18" {...STROKE} />
    </IllustrationSvg>
  )
}

/** No interviews/scheduled events. */
export function EmptyCalendarIllustration(props: SVGProps<SVGSVGElement>) {
  return (
    <IllustrationSvg {...props}>
      <rect x="16" y="22" width="64" height="56" rx="6" {...STROKE} />
      <path d="M16 38h64" {...STROKE} />
      <path d="M30 14v14M66 14v14" {...STROKE} />
      <circle cx="48" cy="58" r="10" {...STROKE} />
      <path d="M48 53v5l4 3" {...STROKE} />
    </IllustrationSvg>
  )
}
