/**
 * Single source of truth for JS-driven motion timing - components that
 * animate via requestAnimationFrame/setTimeout (count-up numbers, list
 * stagger delays) import from here instead of hardcoding their own
 * numbers, so they stay in lockstep with tailwind.config.js's
 * `transitionDuration`/`transitionTimingFunction` extensions (which
 * cover CSS-driven transitions/animations - `duration-fast`,
 * `duration-base`, `duration-slow`, `ease-out`). Keep both in sync by
 * hand if either changes; Tailwind config can't be imported into plain
 * TS the other direction without a build-time cost this app doesn't
 * otherwise pay.
 */
export const DURATION_MS = {
  fast: 120,
  base: 180,
  slow: 400,
} as const

/** Matches tailwind.config.js's `transitionTimingFunction.out`. */
export const EASE_OUT = 'cubic-bezier(0.16, 1, 0.3, 1)'

/** List/card entrance stagger: delay between consecutive items, capped at the Nth item so a long list doesn't feel slow to finish appearing. */
export const STAGGER_MS = 35
export const STAGGER_CAP_INDEX = 5

/** Per-item inline style for a staggered `animate-fade-in` entrance - index is clamped to STAGGER_CAP_INDEX so item 20 doesn't wait 700ms behind item 0. */
export function staggerDelay(index: number): { animationDelay: string } {
  return { animationDelay: `${Math.min(index, STAGGER_CAP_INDEX) * STAGGER_MS}ms` }
}

export function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
}
