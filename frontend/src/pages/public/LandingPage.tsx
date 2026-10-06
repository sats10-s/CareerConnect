import { Link } from 'react-router-dom'
import { ArrowRight, GraduationCap } from 'lucide-react'
import { HeroVisual } from '@/components/marketing/HeroVisual'

/**
 * Public marketing landing page - previously didn't exist at all
 * (RootRedirect sent every guest straight to /login). Reachable at
 * "/" for unauthenticated visitors (see App.tsx's RootRedirect).
 *
 * "Explore Jobs" / "Explore Internships" both route to /login: there's
 * no public, unauthenticated opportunities endpoint (every
 * /student/opportunities route requires a logged-in student), so
 * sending guests anywhere else would either be a dead link or would
 * require inventing a backend capability that doesn't exist. Login is
 * the honest, working destination for "I want to see what's on offer."
 */
const STATS = [
  { value: '12,500+', label: 'Students' },
  { value: '250+', label: 'Companies' },
  { value: '1,200+', label: 'Opportunities' },
]

/**
 * Fonts/spacing/motion polish pass: a hand-tuned entrance stagger for
 * the hero's handful of large elements (badge -> heading -> paragraph
 * -> CTAs -> visual). Not styles/motion.ts's staggerDelay() - that
 * helper's 35ms-per-item cadence is tuned for a list of many small
 * rows, not five large hero elements; a wider gap reads better here.
 */
const HERO_STEP_MS = 90

export function LandingPage() {
  return (
    <div className="animate-fade-in min-h-screen bg-base">
      <header className="border-b border-hairline">
        <div className="mx-auto flex h-20 max-w-6xl items-center justify-between px-4 sm:px-6">
          <span className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-[color:var(--color-primary-ink)]">
              <GraduationCap className="h-5 w-5" strokeWidth={2.2} />
            </span>
            <span className="font-display text-base font-semibold tracking-tight text-ink">CareerConnect</span>
          </span>

          <nav className="flex items-center gap-3">
            <Link to="/company/register" className="hidden text-sm font-medium text-ink-soft transition-colors hover:text-ink md:inline">For recruiters</Link>
            <Link to="/login" className="btn-secondary">Sign in</Link>
            <Link to="/register" className="btn-primary hidden sm:inline-flex">Create account</Link>
          </nav>
        </div>
      </header>

      <main>
        <section className="bg-grid relative mx-auto grid max-w-6xl items-center gap-16 px-4 py-24 sm:px-6 lg:grid-cols-2 lg:gap-10 lg:py-32">
          <div>
            <span className="badge-eyebrow animate-fade-in" style={{ animationDelay: '0ms' }}>
              Internship &amp; Placement Portal
            </span>

            <h1
              className="animate-fade-in mt-6 font-display text-4xl font-bold leading-[1.05] tracking-tight text-ink sm:text-5xl lg:text-[3.75rem]"
              style={{ animationDelay: `${HERO_STEP_MS}ms` }}
            >
              Build Your Career With The <span className="text-accent">Right</span> Opportunity.
            </h1>

            <p
              className="animate-fade-in mt-6 max-w-lg text-base leading-relaxed text-muted sm:text-lg"
              style={{ animationDelay: `${HERO_STEP_MS * 2}ms` }}
            >
              Discover verified internships and placement opportunities through your institution.
            </p>

            <div
              className="animate-fade-in mt-10 flex flex-wrap items-center gap-4"
              style={{ animationDelay: `${HERO_STEP_MS * 3}ms` }}
            >
              <Link to="/login" className="btn-primary">
                Explore Jobs
                <ArrowRight className="h-4 w-4" />
              </Link>
              <Link to="/login" className="btn-accent">
                Explore Internships
                <ArrowRight className="h-4 w-4" />
              </Link>
            </div>
          </div>

          <div className="animate-fade-in" style={{ animationDelay: `${HERO_STEP_MS * 4}ms` }}>
            <HeroVisual />
          </div>
        </section>

        <section className="border-t border-hairline bg-surface">
          <div className="mx-auto grid max-w-6xl grid-cols-3 gap-3 px-4 py-14 sm:gap-6 sm:px-6">
            {STATS.map((stat, index) => (
              <div
                key={stat.label}
                className="card card-hover animate-fade-in min-w-0 px-2 py-6 text-center sm:px-4 sm:py-8"
                style={{ animationDelay: `${index * HERO_STEP_MS}ms` }}
              >
                <p className="font-display text-2xl font-semibold tabular-nums tracking-tight text-primary sm:text-4xl">{stat.value}</p>
                <p className="mt-1.5 text-[10px] font-medium uppercase text-muted sm:text-sm sm:tracking-wide">{stat.label}</p>
              </div>
            ))}
          </div>
        </section>
      </main>
    </div>
  )
}
