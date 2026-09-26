import type { ReactNode } from 'react'

/**
 * Decorative corner-bracket frame - the reference's "corner-bracket-
 * framed panel" motif (its node-constellation graphic uses this
 * around a small mono-caption stat panel). Purely visual: four small
 * L-shaped mint marks at the corners of whatever it wraps. It doesn't
 * add a real border/background of its own - children keep theirs.
 * New this pass - no existing equivalent.
 */
export function CornerFrame({ children, className = '' }: { children: ReactNode; className?: string }) {
  // The corner marks are position:absolute, so this wrapper needs SOME
  // non-static position value to anchor them - but it must not always
  // be "relative": Tailwind emits its position utilities in a fixed
  // order (static, fixed, absolute, relative, sticky) regardless of
  // class order in JSX, so a caller passing "absolute ..." (HeroVisual
  // does, to fill its parent) would have that silently overridden by
  // a hardcoded "relative" landing later in the generated stylesheet.
  // Only fall back to "relative" when the caller hasn't already
  // supplied a position utility of its own.
  const alreadyPositioned = /\b(absolute|fixed|sticky|relative)\b/.test(className)
  const corner = 'pointer-events-none absolute h-3 w-3 border-[color:var(--color-primary)]'
  return (
    <div className={`${alreadyPositioned ? '' : 'relative'} ${className}`}>
      <span className={`${corner} -left-1.5 -top-1.5 border-l-2 border-t-2 opacity-70`} aria-hidden="true" />
      <span className={`${corner} -right-1.5 -top-1.5 border-r-2 border-t-2 opacity-70`} aria-hidden="true" />
      <span className={`${corner} -bottom-1.5 -left-1.5 border-b-2 border-l-2 opacity-70`} aria-hidden="true" />
      <span className={`${corner} -bottom-1.5 -right-1.5 border-b-2 border-r-2 opacity-70`} aria-hidden="true" />
      {children}
    </div>
  )
}
