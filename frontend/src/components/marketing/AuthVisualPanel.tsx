import { GraduationCap, Quote } from 'lucide-react'

/**
 * Left-hand panel of the split auth layout. The spec calls for "a
 * large campus image/visual panel" - no campus photography exists in
 * this project, and the ground rules explicitly say not to reach for
 * a random external image URL, so this is a clean illustrated panel
 * built from existing tokens (same approach as the landing hero's
 * HeroVisual) rather than a placeholder <img>.
 */
export function AuthVisualPanel() {
  return (
    <div
      className="relative hidden h-full flex-col justify-between overflow-hidden p-10 text-white lg:flex"
      style={{ background: 'linear-gradient(160deg, var(--color-primary) 0%, var(--color-primary-hov) 100%)' }}
    >
      <div
        className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-white/5"
        aria-hidden="true"
      />
      <div
        className="pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-white/5"
        aria-hidden="true"
      />

      <span className="flex items-center gap-2">
        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-white/10 text-accent">
          <GraduationCap className="h-5 w-5" strokeWidth={2.2} />
        </span>
        <span className="text-base font-bold">CareerConnect</span>
      </span>

      <div className="max-w-sm">
        <Quote className="h-7 w-7 text-accent" strokeWidth={1.75} />
        <p className="mt-3 text-xl font-medium leading-snug">
          Every application, interview and offer - in one place, verified by your institution.
        </p>
        <p className="mt-4 text-sm text-white/60">
          Internship &amp; Placement Management Portal
        </p>
      </div>
    </div>
  )
}
