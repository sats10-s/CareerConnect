import { useEffect, useRef, useState } from 'react'
import { prefersReducedMotion } from '@/styles/motion'

/**
 * Animates a number from 0 up to `value`, once, on first mount only -
 * per the motion system spec: "Key metrics (dashboard numbers): animate
 * as a count-up from 0 on first mount only - never re-trigger on
 * re-render or tab refocus." A later change to `value` (e.g. a refetch
 * after a withdraw/apply mutation) snaps straight to the new number
 * with no animation - that's the intended behavior per spec, not a
 * missed case. Respects prefers-reduced-motion by skipping straight to
 * the final value (a numeric count has no "opacity-only" equivalent).
 */
export function useCountUp(value: number, durationMs = 500): number {
  const [display, setDisplay] = useState(prefersReducedMotion() ? value : 0)
  const hasAnimated = useRef(false)

  useEffect(() => {
    if (hasAnimated.current || prefersReducedMotion()) {
      setDisplay(value)
      hasAnimated.current = true
      return
    }
    hasAnimated.current = true

    let frame: number
    const start = performance.now()

    function tick(now: number) {
      const elapsed = now - start
      const progress = Math.min(1, elapsed / durationMs)
      // Cubic ease-out, matching the feel of the shared EASE_OUT curve
      // (a raw cubic-bezier can't be evaluated point-by-point cheaply
      // here, so this approximates its shape rather than importing a
      // bezier-solving library for one hook).
      const eased = 1 - Math.pow(1 - progress, 3)
      setDisplay(Math.round(value * eased))
      if (progress < 1) frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, durationMs])

  return display
}

